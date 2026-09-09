<?php
/**
 * Endpoints AJAX del módulo Liberar espacio en Media.
 *
 * @package SEOSystem
 */

defined('ABSPATH') || exit;

if (!function_exists('seo_images_cleanup_ajax_authorize')) {
    function seo_images_cleanup_ajax_authorize() {
        if (!current_user_can('manage_options') || !current_user_can('upload_files')) {
            wp_send_json_error(array('message' => 'No tienes permisos suficientes.'), 403);
        }
        check_ajax_referer('seo_images_cleanup_admin', 'nonce');
    }
}

if (!function_exists('seo_images_cleanup_ajax_start_audit')) {
    function seo_images_cleanup_ajax_start_audit() {
        seo_images_cleanup_ajax_authorize();

        $result = seo_images_cleanup_start_audit();
        if (is_wp_error($result)) {
            wp_send_json_error(array('message' => $result->get_error_message()), 400);
        }

        wp_send_json_success(array(
            'state' => $result,
            'stats'        => seo_images_cleanup_candidate_stats(),
            'delete_stats' => seo_images_cleanup_delete_stats(),
        ));
    }
}
add_action('wp_ajax_seo_images_cleanup_start_audit', 'seo_images_cleanup_ajax_start_audit');

if (!function_exists('seo_images_cleanup_ajax_audit_batch')) {
    function seo_images_cleanup_ajax_audit_batch() {
        seo_images_cleanup_ajax_authorize();

        $result = seo_images_cleanup_run_audit_batch();
        if (is_wp_error($result)) {
            wp_send_json_error(array(
                'message' => $result->get_error_message(),
                'state'   => seo_images_cleanup_get_state(),
            ), 500);
        }

        wp_send_json_success(array(
            'state' => $result,
            'stats'        => seo_images_cleanup_candidate_stats(),
            'delete_stats' => seo_images_cleanup_delete_stats(),
        ));
    }
}
add_action('wp_ajax_seo_images_cleanup_audit_batch', 'seo_images_cleanup_ajax_audit_batch');

if (!function_exists('seo_images_cleanup_ajax_delete_batch')) {
    function seo_images_cleanup_ajax_delete_batch() {
        seo_images_cleanup_ajax_authorize();

        $confirmed = isset($_POST['confirm']) ? sanitize_key(wp_unslash($_POST['confirm'])) : '';
        if ($confirmed !== '1') {
            wp_send_json_error(array('message' => 'Falta la confirmación explícita de borrado.'), 400);
        }

        $result = seo_images_cleanup_delete_batch(10);

        if (!empty($result['error'])) {
            wp_send_json_error(array(
                'message' => $result['error'],
                'stats'   => $result['stats'],
            ), 400);
        }

        wp_send_json_success($result);
    }
}
add_action('wp_ajax_seo_images_cleanup_delete_batch', 'seo_images_cleanup_ajax_delete_batch');

if (!function_exists('seo_images_cleanup_ajax_retry_errors')) {
    function seo_images_cleanup_ajax_retry_errors() {
        seo_images_cleanup_ajax_authorize();

        $reset = seo_images_cleanup_retry_errors();
        wp_send_json_success(array(
            'reset' => $reset,
            'stats' => seo_images_cleanup_delete_stats(),
        ));
    }
}
add_action('wp_ajax_seo_images_cleanup_retry_errors', 'seo_images_cleanup_ajax_retry_errors');
