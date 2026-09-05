<?php
/**
 * Bootstrap del subsistema de Procesos.
 *
 * Centraliza el registro de pestanas y la carga de los modulos que amplian
 * SEO Taxonomy > Procesos.
 *
 * @package SEOSystem
 * @subpackage Processes
 * @since 2.3.3
 */

defined('ABSPATH') || exit;

if (!function_exists('seo_processes_register_tab')) {
    /**
     * Registra una pestana adicional en la pantalla Procesos.
     *
     * @param string   $id       Identificador de la pestana.
     * @param string   $label    Etiqueta visible.
     * @param callable $callback Renderizador de la pestana.
     * @param int      $order    Orden relativo.
     */
    function seo_processes_register_tab($id, $label, $callback, $order = 50) {
        $id = sanitize_key((string) $id);
        if (!$id || in_array($id, array('processes', 'workers'), true)) {
            return false;
        }

        if (!isset($GLOBALS['seo_processes_registered_tabs']) || !is_array($GLOBALS['seo_processes_registered_tabs'])) {
            $GLOBALS['seo_processes_registered_tabs'] = array();
        }

        $GLOBALS['seo_processes_registered_tabs'][$id] = array(
            'id'       => $id,
            'label'    => sanitize_text_field((string) $label),
            'callback' => $callback,
            'order'    => absint($order),
        );

        return true;
    }
}

if (!function_exists('seo_processes_tabs')) {
    function seo_processes_tabs() {
        $tabs = array(
            'processes' => array(
                'id'       => 'processes',
                'label'    => 'Procesos',
                'callback' => null,
                'order'    => 10,
            ),
            'workers' => array(
                'id'       => 'workers',
                'label'    => 'Gestor de workers',
                'callback' => 'seo_process_supervisor_render_page',
                'order'    => 20,
            ),
        );

        $registered = $GLOBALS['seo_processes_registered_tabs'] ?? array();
        if (is_array($registered)) {
            foreach ($registered as $id => $tab) {
                if (is_array($tab) && !isset($tabs[$id])) {
                    $tabs[$id] = $tab;
                }
            }
        }

        uasort($tabs, static function ($a, $b) {
            return ((int) ($a['order'] ?? 50)) <=> ((int) ($b['order'] ?? 50));
        });

        return $tabs;
    }
}

if (!function_exists('seo_processes_render_tabs')) {
    function seo_processes_render_tabs($active = 'processes') {
        $active = sanitize_key((string) $active);
        echo '<h2 class="nav-tab-wrapper" style="margin-bottom:18px">';

        foreach (seo_processes_tabs() as $id => $tab) {
            $args = array('page' => 'seo-processes');
            if ('processes' !== $id) {
                $args['tab'] = $id;
            }
            $class = 'nav-tab' . ($active === $id ? ' nav-tab-active' : '');
            echo '<a class="' . esc_attr($class) . '" href="' . esc_url(add_query_arg($args, admin_url('admin.php'))) . '">' . esc_html((string) ($tab['label'] ?? $id)) . '</a>';
        }

        echo '</h2>';
    }
}

if (!function_exists('seo_processes_render_registered_tab')) {
    function seo_processes_render_registered_tab($tab_id) {
        $tab_id = sanitize_key((string) $tab_id);
        if (!$tab_id || in_array($tab_id, array('processes', 'workers'), true)) {
            return false;
        }

        $tabs = seo_processes_tabs();
        if (empty($tabs[$tab_id]['callback']) || !is_callable($tabs[$tab_id]['callback'])) {
            return false;
        }

        call_user_func($tabs[$tab_id]['callback']);
        return true;
    }
}

require_once SEO_SYSTEM_PATH . 'includes/seo-process-supervisor.php';
require_once SEO_SYSTEM_PATH . 'includes/procesos/woocommerce/bootstrap.php';
require_once SEO_SYSTEM_PATH . 'includes/seo-processes.php';
