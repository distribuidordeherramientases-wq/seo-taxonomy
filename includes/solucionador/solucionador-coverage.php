<?php
/**
 * Compatibilidad histórica de cobertura para Solucionador.
 *
 * La implementación vive en SEO_Editorial_Coverage. Este wrapper evita romper
 * llamadas antiguas durante la transición de los procesos editoriales.
 */

defined('ABSPATH') || exit;

final class SEO_Solucionador_Coverage {
    public static function rebuild_index($limit = 5000) {
        return SEO_Editorial_Coverage::rebuild_index($limit);
    }

    public static function rebuild_post_index($limit = 5000) {
        return SEO_Editorial_Coverage::rebuild_post_index($limit);
    }

    public static function find(array $profile) {
        return SEO_Editorial_Coverage::find($profile);
    }
}
