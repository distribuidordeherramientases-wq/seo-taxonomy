<?php
/**
 * Respeta las asignaciones de correo existentes en seo_mail_templates.
 *
 * El gestor antiguo habia asociado clases de correo a claves de
 * seo_templates. Se conserva el mismo comportamiento al desactivar
 * el monolito, siempre que la nueva biblioteca disponga de los PHP.
 */
defined('ABSPATH') || exit;

final class TCT_Legacy_Email_Compat {
    public static function table_exists($table) {
        global $wpdb;
        return $table === $wpdb->get_var($wpdb->prepare(
            'SHOW TABLES LIKE %s', $wpdb->esc_like($table)
        ));
    }

    public static function mappings() {
        if (!TaxoCommerce_Templates::installed()) {
            return array();
        }
        global $wpdb;
        $mail = $wpdb->prefix . 'seo_mail_templates';
        $templates = TaxoCommerce_Templates::templates();
        if (!self::table_exists($mail)) {
            return array();
        }

        $rows = (array)$wpdb->get_results(
            "SELECT mail.mail_key, tpl.template_file
             FROM {$mail} AS mail
             INNER JOIN {$templates} AS tpl
               ON tpl.template_key = mail.template_key
             WHERE mail.is_enabled = 1
               AND tpl.is_active = 1"
        );
        $result = array();
        foreach ($rows as $row) {
            $mail_key = sanitize_key((string)$row->mail_key);
            $file = (string)$row->template_file;
            $path = TCT_Router::path($file);
            if ($mail_key !== '' && $path !== '') {
                $result[$mail_key] = array(
                    'filename' => $file,
                    'path' => $path
                );
            }
        }
        return $result;
    }

    public static function apply($emails) {
        if (!TaxoCommerce_Templates::policies_enabled() ||
            !is_array($emails)) {
            return $emails;
        }

        $mappings = self::mappings();
        if (!$mappings) {
            return $emails;
        }
        foreach ($emails as $key => $email) {
            if (!is_object($email) || !isset($email->id)) {
                continue;
            }
            $mail_key = sanitize_key((string)$email->id);
            if (!isset($mappings[$mail_key])) {
                continue;
            }
            $email->template_html = $mappings[$mail_key]['filename'];
            $email->template_base = trailingslashit(dirname($mappings[$mail_key]['path']));
            $emails[$key] = $email;
        }
        return $emails;
    }
}
