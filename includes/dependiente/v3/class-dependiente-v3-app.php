<?php

defined('ABSPATH') || exit;

final class SEO_Dependiente_V3_App {
    private static $booted = false;

    public static function boot() {
        if (self::$booted) return;
        self::$booted = true;

        // L9 y L10 pertenecen a la infraestructura de conocimiento de V3, no al
        // bootstrap legacy. Cargarlas aquí evita volver a sustituir el bootstrap
        // que mantiene Academia, indexación, supervisor e import/export.
        $lesson9_file = __DIR__ . '/training/class-dependiente-v3-lesson9.php';
        if (is_readable($lesson9_file)) {
            require_once $lesson9_file;
            if (class_exists('SEO_Dependiente_V3_Lesson9')) {
                SEO_Dependiente_V3_Lesson9::init();
            }
        }

        $lesson10_file = __DIR__ . '/training/class-dependiente-v3-lesson10.php';
        if (is_readable($lesson10_file)) {
            require_once $lesson10_file;
        }

        SEO_Dependiente_V3_API::init();
        SEO_Dependiente_V3_Frontend::init();
    }
}
