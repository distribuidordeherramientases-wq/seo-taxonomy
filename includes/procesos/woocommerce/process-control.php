<?php
/**
 * Control de intensidad de procesos WooCommerce.
 *
 * Se integra como tercera pestaña de SEO Taxonomy > Procesos.
 * El objetivo es reducir trabajo de mantenimiento que no necesita inmediatez
 * sin interferir por defecto con pagos, pedidos, webhooks o correos.
 */

defined('ABSPATH') || exit;

if (!defined('SEO_WC_PROCESS_CONTROL_OPTION')) {
    define('SEO_WC_PROCESS_CONTROL_OPTION', 'seo_wc_process_control_settings');
}

if (!function_exists('seo_wc_process_control_defaults')) {
    function seo_wc_process_control_defaults() {
        return array(
            'sales_mode'            => 'exact',
            'sales_sweep_interval'  => 86400,
            'sessions_mode'         => 'woocommerce',
            'sessions_interval'     => 43200,
            'runner_limit_enabled'  => 0,
            'runner_batch_size'     => 10,
            'runner_time_limit'     => 15,
            'runner_async_sleep'    => 10,
        );
    }
}

if (!function_exists('seo_wc_process_control_settings')) {
    function seo_wc_process_control_settings() {
        $stored = get_option(SEO_WC_PROCESS_CONTROL_OPTION, array());
        $stored = is_array($stored) ? $stored : array();
        return wp_parse_args($stored, seo_wc_process_control_defaults());
    }
}

if (!function_exists('seo_wc_process_control_interval_options')) {
    function seo_wc_process_control_interval_options() {
        return array(
            3600  => 'Cada hora',
            21600 => 'Cada 6 horas',
            43200 => 'Cada 12 horas',
            86400 => 'Una vez al día',
        );
    }
}

if (!function_exists('seo_wc_process_control_sanitize')) {
    function seo_wc_process_control_sanitize($raw) {
        $defaults = seo_wc_process_control_defaults();
        $raw = is_array($raw) ? $raw : array();
        $out = $defaults;

        $sales_mode = sanitize_key((string)($raw['sales_mode'] ?? $defaults['sales_mode']));
        $out['sales_mode'] = in_array($sales_mode, array('exact', 'sweep'), true) ? $sales_mode : 'exact';

        $intervals = array_keys(seo_wc_process_control_interval_options());
        $sales_interval = absint($raw['sales_sweep_interval'] ?? $defaults['sales_sweep_interval']);
        $out['sales_sweep_interval'] = in_array($sales_interval, $intervals, true) ? $sales_interval : 86400;

        $sessions_mode = sanitize_key((string)($raw['sessions_mode'] ?? $defaults['sessions_mode']));
        $out['sessions_mode'] = in_array($sessions_mode, array('woocommerce', 'custom'), true) ? $sessions_mode : 'woocommerce';

        $sessions_interval = absint($raw['sessions_interval'] ?? $defaults['sessions_interval']);
        $out['sessions_interval'] = in_array($sessions_interval, $intervals, true) ? $sessions_interval : 43200;

        $out['runner_limit_enabled'] = empty($raw['runner_limit_enabled']) ? 0 : 1;
        $out['runner_batch_size'] = max(1, min(25, absint($raw['runner_batch_size'] ?? $defaults['runner_batch_size'])));
        $out['runner_time_limit'] = max(5, min(30, absint($raw['runner_time_limit'] ?? $defaults['runner_time_limit'])));
        $out['runner_async_sleep'] = max(5, min(30, absint($raw['runner_async_sleep'] ?? $defaults['runner_async_sleep'])));

        return $out;
    }
}

if (!function_exists('seo_wc_process_control_block_exact_sale_action')) {
    /**
     * En modo agrupado evita que WooCommerce vuelva a crear una acción individual
     * por cada inicio/fin de oferta. El barrido woocommerce_scheduled_sales sigue vivo.
     */
    function seo_wc_process_control_block_exact_sale_action($pre, $timestamp, $hook, $args, $group, $priority, $unique) {
        unset($timestamp, $args, $priority, $unique);
        $settings = seo_wc_process_control_settings();
        if ('sweep' !== $settings['sales_mode']) {
            return $pre;
        }

        if (
            in_array((string)$hook, array('wc_product_start_scheduled_sale', 'wc_product_end_scheduled_sale'), true)
            && ('' === (string)$group || 'woocommerce-sales' === (string)$group)
        ) {
            return 0;
        }

        return $pre;
    }
}
add_filter('pre_as_schedule_single_action', 'seo_wc_process_control_block_exact_sale_action', 20, 7);

if (!function_exists('seo_wc_process_control_filter_batch_size')) {
    function seo_wc_process_control_filter_batch_size($size) {
        $settings = seo_wc_process_control_settings();
        if (empty($settings['runner_limit_enabled'])) {
            return $size;
        }
        return min(absint($size), absint($settings['runner_batch_size']));
    }
}
add_filter('action_scheduler_queue_runner_batch_size', 'seo_wc_process_control_filter_batch_size', 99);

if (!function_exists('seo_wc_process_control_filter_time_limit')) {
    function seo_wc_process_control_filter_time_limit($seconds) {
        $settings = seo_wc_process_control_settings();
        if (empty($settings['runner_limit_enabled'])) {
            return $seconds;
        }
        return min((int)$seconds, (int)$settings['runner_time_limit']);
    }
}
add_filter('action_scheduler_queue_runner_time_limit', 'seo_wc_process_control_filter_time_limit', 99);

if (!function_exists('seo_wc_process_control_filter_concurrent_batches')) {
    function seo_wc_process_control_filter_concurrent_batches($batches) {
        $settings = seo_wc_process_control_settings();
        if (empty($settings['runner_limit_enabled'])) {
            return $batches;
        }
        return 1;
    }
}
add_filter('action_scheduler_queue_runner_concurrent_batches', 'seo_wc_process_control_filter_concurrent_batches', 99);

if (!function_exists('seo_wc_process_control_filter_async_sleep')) {
    function seo_wc_process_control_filter_async_sleep($seconds) {
        $settings = seo_wc_process_control_settings();
        if (empty($settings['runner_limit_enabled'])) {
            return $seconds;
        }
        return max((int)$seconds, (int)$settings['runner_async_sleep']);
    }
}
add_filter('action_scheduler_async_request_sleep_seconds', 'seo_wc_process_control_filter_async_sleep', 99);

if (!function_exists('seo_wc_process_control_action_scheduler_ready')) {
    function seo_wc_process_control_action_scheduler_ready() {
        return function_exists('as_schedule_recurring_action')
            && function_exists('as_unschedule_all_actions')
            && (!function_exists('did_action') || did_action('action_scheduler_init'));
    }
}

if (!function_exists('seo_wc_process_control_unschedule_hook')) {
    function seo_wc_process_control_unschedule_hook($hook) {
        if (function_exists('as_unschedule_all_actions')) {
            as_unschedule_all_actions($hook);
        }
        if (function_exists('wp_clear_scheduled_hook')) {
            wp_clear_scheduled_hook($hook);
        }
    }
}

if (!function_exists('seo_wc_process_control_schedule_recurring')) {
    function seo_wc_process_control_schedule_recurring($hook, $interval, $group) {
        $interval = max(HOUR_IN_SECONDS, absint($interval));
        $start = time() + 300;

        if (function_exists('as_next_scheduled_action')) {
            $current = as_next_scheduled_action($hook);
            if (is_int($current) && $current > time()) {
                $start = $current;
            }
        } else {
            $current = wp_next_scheduled($hook);
            if ($current && $current > time()) {
                $start = $current;
            }
        }

        seo_wc_process_control_unschedule_hook($hook);

        if (seo_wc_process_control_action_scheduler_ready()) {
            as_schedule_recurring_action($start, $interval, $hook, array(), (string)$group, true);
            return true;
        }

        return false;
    }
}

if (!function_exists('seo_wc_process_control_apply_sales_policy')) {
    function seo_wc_process_control_apply_sales_policy($settings) {
        if (!is_array($settings)) {
            return;
        }

        if ('sweep' === $settings['sales_mode']) {
            if (function_exists('as_unschedule_all_actions')) {
                as_unschedule_all_actions('wc_product_start_scheduled_sale');
                as_unschedule_all_actions('wc_product_end_scheduled_sale');
            }
            seo_wc_process_control_schedule_recurring(
                'woocommerce_scheduled_sales',
                absint($settings['sales_sweep_interval']),
                'woocommerce'
            );
        }
    }
}

if (!function_exists('seo_wc_process_control_apply_sessions_policy')) {
    function seo_wc_process_control_apply_sessions_policy($settings) {
        if (!is_array($settings) || 'custom' !== $settings['sessions_mode']) {
            return;
        }
        seo_wc_process_control_schedule_recurring(
            'woocommerce_cleanup_sessions',
            absint($settings['sessions_interval']),
            'woocommerce'
        );
    }
}

if (!function_exists('seo_wc_process_control_save')) {
    function seo_wc_process_control_save() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('No autorizado.', 'seo-taxonomy'));
        }
        check_admin_referer('seo_wc_process_control_save');

        $previous = seo_wc_process_control_settings();
        $raw = isset($_POST['wc_control']) && is_array($_POST['wc_control']) ? wp_unslash($_POST['wc_control']) : array();
        $settings = seo_wc_process_control_sanitize($raw);
        update_option(SEO_WC_PROCESS_CONTROL_OPTION, $settings, false);

        $messages = array('saved');

        if ('sweep' === $settings['sales_mode']) {
            seo_wc_process_control_apply_sales_policy($settings);
            $messages[] = 'sales';
        } elseif ('sweep' === $previous['sales_mode'] && 'exact' === $settings['sales_mode']) {
            // Restaura el barrido de seguridad diario de WooCommerce. No reconstruimos
            // miles de acciones exactas en una sola petición administrativa.
            seo_wc_process_control_schedule_recurring('woocommerce_scheduled_sales', DAY_IN_SECONDS, 'woocommerce');
            $messages[] = 'exact_pending_rebuild';
        }

        if ('custom' === $settings['sessions_mode']) {
            seo_wc_process_control_apply_sessions_policy($settings);
            $messages[] = 'sessions';
        } elseif ('custom' === $previous['sessions_mode'] && 'woocommerce' === $settings['sessions_mode']) {
            // WooCommerce core programa esta limpieza cada 12 horas.
            seo_wc_process_control_schedule_recurring('woocommerce_cleanup_sessions', 12 * HOUR_IN_SECONDS, 'woocommerce');
            $messages[] = 'sessions_default';
        }

        wp_safe_redirect(add_query_arg(array(
            'page'       => 'seo-processes',
            'tab'        => 'woocommerce',
            'wc_control' => implode(',', $messages),
        ), admin_url('admin.php')));
        exit;
    }
}
add_action('admin_post_seo_wc_process_control_save', 'seo_wc_process_control_save');

if (!function_exists('seo_wc_process_control_reset')) {
    function seo_wc_process_control_reset() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('No autorizado.', 'seo-taxonomy'));
        }
        check_admin_referer('seo_wc_process_control_reset');
        delete_option(SEO_WC_PROCESS_CONTROL_OPTION);

        // Restauramos los dos recurring jobs que este módulo puede modificar.
        // WooCommerce usa 1 día para ventas programadas y 12 horas para sesiones.
        seo_wc_process_control_schedule_recurring('woocommerce_scheduled_sales', DAY_IN_SECONDS, 'woocommerce');
        seo_wc_process_control_schedule_recurring('woocommerce_cleanup_sessions', 12 * HOUR_IN_SECONDS, 'woocommerce');

        wp_safe_redirect(add_query_arg(array(
            'page'       => 'seo-processes',
            'tab'        => 'woocommerce',
            'wc_control' => 'reset',
        ), admin_url('admin.php')));
        exit;
    }
}
add_action('admin_post_seo_wc_process_control_reset', 'seo_wc_process_control_reset');

if (!function_exists('seo_wc_process_control_table_exists')) {
    function seo_wc_process_control_table_exists($table) {
        global $wpdb;
        return (string)$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table))) === (string)$table;
    }
}

if (!function_exists('seo_wc_process_control_snapshot')) {
    function seo_wc_process_control_snapshot() {
        global $wpdb;
        $actions = $wpdb->prefix . 'actionscheduler_actions';
        if (!seo_wc_process_control_table_exists($actions)) {
            return array(
                'available' => false,
                'pending' => 0,
                'overdue' => 0,
                'failed' => 0,
                'exact_sales' => 0,
                'top_hooks' => array(),
            );
        }

        $pending = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$actions} WHERE status='pending'");
        $overdue = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$actions} WHERE status='pending' AND scheduled_date_gmt <= UTC_TIMESTAMP()");
        $failed = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$actions} WHERE status='failed'");
        $exact_sales = (int)$wpdb->get_var(
            "SELECT COUNT(*) FROM {$actions} WHERE status='pending' AND hook IN ('wc_product_start_scheduled_sale','wc_product_end_scheduled_sale')"
        );

        $rows = (array)$wpdb->get_results(
            "SELECT hook, status, COUNT(*) AS total, MIN(scheduled_date_gmt) AS first_date, MAX(scheduled_date_gmt) AS last_date
             FROM {$actions}
             WHERE status IN ('pending','failed')
             GROUP BY hook, status
             ORDER BY status DESC, total DESC
             LIMIT 25",
            ARRAY_A
        );

        return array(
            'available' => true,
            'pending' => $pending,
            'overdue' => $overdue,
            'failed' => $failed,
            'exact_sales' => $exact_sales,
            'top_hooks' => $rows,
        );
    }
}

if (!function_exists('seo_wc_process_control_next_action')) {
    function seo_wc_process_control_next_action($hook) {
        if (function_exists('as_next_scheduled_action')) {
            $next = as_next_scheduled_action($hook);
            if (is_int($next) && $next > 0) {
                return wp_date('Y-m-d H:i:s', $next);
            }
            if (true === $next) {
                return 'En ejecución';
            }
        }
        $wp_next = wp_next_scheduled($hook);
        return $wp_next ? wp_date('Y-m-d H:i:s', $wp_next) : 'No programado';
    }
}

if (!function_exists('seo_wc_process_control_hook_level')) {
    function seo_wc_process_control_hook_level($hook) {
        $hook = strtolower((string)$hook);
        foreach (array('stripe', 'wcpay', 'payment', 'webhook', 'refund', 'subscription', 'order') as $protected) {
            if (false !== strpos($hook, $protected)) {
                return 'protected';
            }
        }
        if (in_array($hook, array(
            'wc_product_start_scheduled_sale',
            'wc_product_end_scheduled_sale',
            'woocommerce_scheduled_sales',
            'woocommerce_cleanup_sessions',
            'woocommerce_cleanup_logs',
            'woocommerce_cleanup_draft_orders',
            'woocommerce_cleanup_personal_data',
            'woocommerce_cleanup_rate_limits_wrapper',
            'woocommerce_tracker_send_event_wrapper',
            'wc_admin_daily_wrapper',
            'woocommerce_geoip_updater',
        ), true)) {
            return 'maintenance';
        }
        return 'observe';
    }
}

if (!function_exists('seo_wc_process_control_level_label')) {
    function seo_wc_process_control_level_label($level) {
        if ('protected' === $level) return 'Protegido';
        if ('maintenance' === $level) return 'Mantenimiento';
        return 'Solo observar';
    }
}

if (!function_exists('seo_wc_process_control_render_page')) {
    function seo_wc_process_control_render_page() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('No tienes permisos para acceder a esta página.', 'seo-taxonomy'));
        }

        $settings = seo_wc_process_control_settings();
        $snapshot = seo_wc_process_control_snapshot();
        $intervals = seo_wc_process_control_interval_options();
        $message = sanitize_text_field(wp_unslash($_GET['wc_control'] ?? ''));
        ?>
        <div class="wrap seo-wc-process-control">
            <h1>Procesos</h1>
            <?php if (function_exists('seo_processes_render_tabs')) { seo_processes_render_tabs('woocommerce'); } ?>

            <div class="seo-wc-process-heading">
                <div>
                    <h2>WooCommerce · intensidad y mantenimiento</h2>
                    <p>Controla procesos de mantenimiento que no necesitan respuesta inmediata. Pagos, pedidos, webhooks y tareas transaccionales se muestran para diagnóstico, pero no se reprograman individualmente desde esta pantalla.</p>
                </div>
                <span>WooCommerce: <strong><?php echo class_exists('WooCommerce') ? 'activo' : 'no detectado'; ?></strong></span>
            </div>

            <?php if ($message) : ?>
                <div class="notice notice-success is-dismissible"><p>
                    <?php
                    if (false !== strpos($message, 'reset')) {
                        echo esc_html('Controles restaurados. Se han repuesto el barrido diario de ofertas y la limpieza de sesiones cada 12 horas.');
                    } else {
                        echo esc_html('Configuración guardada y políticas seleccionadas aplicadas.');
                        if (false !== strpos($message, 'exact_pending_rebuild')) {
                            echo '<br><strong>' . esc_html('Modo exacto reactivado:') . '</strong> ' . esc_html('las acciones individuales futuras se recrearán al guardar o sincronizar cada producto; no se reconstruyen miles de acciones en una sola petición.');
                        }
                    }
                    ?>
                </p></div>
            <?php endif; ?>

            <div class="seo-wc-summary">
                <div><strong><?php echo esc_html(number_format_i18n((int)$snapshot['pending'])); ?></strong><span>Pendientes</span></div>
                <div><strong><?php echo esc_html(number_format_i18n((int)$snapshot['overdue'])); ?></strong><span>Vencidas</span></div>
                <div><strong><?php echo esc_html(number_format_i18n((int)$snapshot['failed'])); ?></strong><span>Fallidas</span></div>
                <div><strong><?php echo esc_html(number_format_i18n((int)$snapshot['exact_sales'])); ?></strong><span>Ofertas exactas pendientes</span></div>
            </div>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="seo-wc-card">
                <input type="hidden" name="action" value="seo_wc_process_control_save">
                <?php wp_nonce_field('seo_wc_process_control_save'); ?>

                <h2>Control de intensidad</h2>
                <div class="seo-wc-grid">
                    <section>
                        <h3>Ofertas programadas</h3>
                        <label><input type="radio" name="wc_control[sales_mode]" value="exact" <?php checked($settings['sales_mode'], 'exact'); ?>> <strong>Exactas (WooCommerce)</strong></label>
                        <small>Una acción por producto y por fecha de inicio/fin. Máxima precisión temporal.</small>
                        <label><input type="radio" name="wc_control[sales_mode]" value="sweep" <?php checked($settings['sales_mode'], 'sweep'); ?>> <strong>Agrupadas / baja intensidad</strong></label>
                        <small>Bloquea las acciones individuales y deja un barrido periódico de <code>woocommerce_scheduled_sales</code>.</small>
                        <label class="seo-wc-field">Frecuencia del barrido
                            <select name="wc_control[sales_sweep_interval]">
                                <?php foreach ($intervals as $seconds => $label) : ?>
                                    <option value="<?php echo esc_attr((string)$seconds); ?>" <?php selected((int)$settings['sales_sweep_interval'], (int)$seconds); ?>><?php echo esc_html($label); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <p class="description">Siguiente barrido: <strong><?php echo esc_html(seo_wc_process_control_next_action('woocommerce_scheduled_sales')); ?></strong></p>
                    </section>

                    <section>
                        <h3>Sesiones / carritos expirados</h3>
                        <label><input type="radio" name="wc_control[sessions_mode]" value="woocommerce" <?php checked($settings['sessions_mode'], 'woocommerce'); ?>> <strong>Dejar frecuencia WooCommerce (12 h)</strong></label>
                        <small>Mantiene el valor estándar de WooCommerce: limpieza dos veces al día.</small>
                        <label><input type="radio" name="wc_control[sessions_mode]" value="custom" <?php checked($settings['sessions_mode'], 'custom'); ?>> <strong>Frecuencia personalizada</strong></label>
                        <small>Solo cambia cuándo se limpian sesiones ya expiradas; no cambia la duración del carrito activo.</small>
                        <label class="seo-wc-field">Frecuencia de limpieza
                            <select name="wc_control[sessions_interval]">
                                <?php foreach ($intervals as $seconds => $label) : ?>
                                    <option value="<?php echo esc_attr((string)$seconds); ?>" <?php selected((int)$settings['sessions_interval'], (int)$seconds); ?>><?php echo esc_html($label); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <p class="description">Siguiente limpieza: <strong><?php echo esc_html(seo_wc_process_control_next_action('woocommerce_cleanup_sessions')); ?></strong></p>
                    </section>

                    <section>
                        <h3>Action Scheduler · limitador global</h3>
                        <label><input type="checkbox" name="wc_control[runner_limit_enabled]" value="1" <?php checked(!empty($settings['runner_limit_enabled'])); ?>> <strong>Limitar intensidad por ejecución</strong></label>
                        <small>Afecta a toda la cola Action Scheduler, incluidos otros plugins. No cambia la frecuencia del cron; reduce el trabajo que intenta hacer cada ejecución.</small>
                        <label class="seo-wc-field">Acciones por lote
                            <input type="number" name="wc_control[runner_batch_size]" min="1" max="25" value="<?php echo esc_attr((string)$settings['runner_batch_size']); ?>">
                        </label>
                        <label class="seo-wc-field">Tiempo máximo por runner (s)
                            <input type="number" name="wc_control[runner_time_limit]" min="5" max="30" value="<?php echo esc_attr((string)$settings['runner_time_limit']); ?>">
                        </label>
                        <label class="seo-wc-field">Pausa entre runners asíncronos (s)
                            <input type="number" name="wc_control[runner_async_sleep]" min="5" max="30" value="<?php echo esc_attr((string)$settings['runner_async_sleep']); ?>">
                        </label>
                        <p class="seo-wc-warning"><strong>Recomendación:</strong> empieza con este limitador desactivado. Primero reduce ofertas exactas y mantenimiento; solo actívalo si la cola sigue generando presión.</p>
                    </section>
                </div>

                <p class="submit"><button type="submit" class="button button-primary">Guardar y aplicar</button></p>
            </form>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="seo-wc-reset">
                <input type="hidden" name="action" value="seo_wc_process_control_reset">
                <?php wp_nonce_field('seo_wc_process_control_reset'); ?>
                <button type="submit" class="button">Restaurar controles del plugin</button>
                <span class="description">No borra pedidos, sesiones, precios ni acciones de WooCommerce.</span>
            </form>

            <div class="seo-wc-card">
                <h2>Cola observable</h2>
                <p>Los procesos transaccionales quedan marcados como protegidos. Esta tabla es diagnóstico; los controles de arriba solo actúan sobre ofertas, limpieza de sesiones y, opcionalmente, el límite global del runner.</p>
                <?php if (empty($snapshot['available'])) : ?>
                    <p>No se ha encontrado la tabla de Action Scheduler.</p>
                <?php elseif (empty($snapshot['top_hooks'])) : ?>
                    <p>No hay acciones pendientes o fallidas.</p>
                <?php else : ?>
                    <div class="seo-wc-table-wrap"><table class="widefat striped">
                        <thead><tr><th>Hook</th><th>Tipo</th><th>Estado</th><th>Total</th><th>Primera</th><th>Última</th></tr></thead>
                        <tbody>
                        <?php foreach ($snapshot['top_hooks'] as $row) : $level = seo_wc_process_control_hook_level($row['hook']); ?>
                            <tr>
                                <td><code><?php echo esc_html((string)$row['hook']); ?></code></td>
                                <td><span class="seo-wc-pill is-<?php echo esc_attr($level); ?>"><?php echo esc_html(seo_wc_process_control_level_label($level)); ?></span></td>
                                <td><?php echo esc_html((string)$row['status']); ?></td>
                                <td><?php echo esc_html(number_format_i18n((int)$row['total'])); ?></td>
                                <td><?php echo esc_html((string)$row['first_date']); ?></td>
                                <td><?php echo esc_html((string)$row['last_date']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table></div>
                <?php endif; ?>
            </div>
        </div>
        <style>
            .seo-wc-process-control{max-width:1500px}.seo-wc-process-heading{display:flex;justify-content:space-between;gap:20px;align-items:flex-start;margin:18px 0}.seo-wc-process-heading h2{margin:0 0 5px}.seo-wc-process-heading p{margin:0;max-width:980px;color:#50575e}.seo-wc-process-heading>span{font-size:12px;color:#646970}.seo-wc-summary{display:grid;grid-template-columns:repeat(4,minmax(140px,1fr));gap:10px;margin-bottom:18px}.seo-wc-summary>div{background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:14px 16px}.seo-wc-summary strong{display:block;font-size:24px}.seo-wc-summary span{display:block;margin-top:4px;color:#646970}.seo-wc-card{background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:18px;margin-bottom:16px}.seo-wc-card>h2{margin-top:0}.seo-wc-grid{display:grid;grid-template-columns:repeat(3,minmax(260px,1fr));gap:16px}.seo-wc-grid section{border:1px solid #e2e4e7;border-radius:7px;padding:15px}.seo-wc-grid h3{margin-top:0}.seo-wc-grid section>label:not(.seo-wc-field){display:block;margin:9px 0 2px}.seo-wc-grid small{display:block;color:#646970;margin:0 0 10px 24px}.seo-wc-field{display:block;font-weight:600;margin-top:12px}.seo-wc-field select,.seo-wc-field input[type=number]{display:block;margin-top:5px;max-width:220px;width:100%}.seo-wc-warning{background:#fff8e5;border-left:4px solid #dba617;padding:9px 11px}.seo-wc-reset{display:flex;gap:10px;align-items:center;margin:0 0 18px}.seo-wc-table-wrap{overflow:auto}.seo-wc-table-wrap table{min-width:900px}.seo-wc-pill{display:inline-block;border-radius:999px;padding:3px 8px;font-size:11px;font-weight:700;background:#f0f0f1}.seo-wc-pill.is-maintenance{background:#f0f6fc;color:#135e96}.seo-wc-pill.is-protected{background:#fcf0f1;color:#8a2424}.seo-wc-pill.is-observe{background:#f6f7f7;color:#50575e}@media(max-width:1000px){.seo-wc-grid{grid-template-columns:1fr}.seo-wc-summary{grid-template-columns:repeat(2,minmax(130px,1fr))}}@media(max-width:600px){.seo-wc-process-heading{display:block}.seo-wc-summary{grid-template-columns:1fr}.seo-wc-reset{display:block}.seo-wc-reset .description{display:block;margin-top:7px}}
        </style>
        <?php
    }
}
