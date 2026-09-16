<?php

defined('ABSPATH') || exit;

final class SEO_Dependiente_V3_App {
    private static $booted = false;

    public static function boot() {
        if (self::$booted) return;
        self::$booted = true;
        SEO_Dependiente_V3_API::init();
        SEO_Dependiente_V3_Frontend::init();
    }
}
