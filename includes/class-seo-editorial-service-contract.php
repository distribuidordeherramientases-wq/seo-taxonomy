<?php
/**
 * Contrato editorial comun para servicios category-first.
 *
 * No conoce las tablas de origen de Solucionador, Ingeniero o Comparador.
 * Unicamente normaliza estados y reglas de transicion que deben compartir.
 */

defined('ABSPATH') || exit;

final class SEO_Editorial_Service_Contract {
    const COLLECTING       = 'COLLECTING';
    const READY_FOR_REVIEW = 'READY_FOR_REVIEW';
    const ACCEPTED         = 'ACCEPTED';
    const DRAFT            = 'DRAFT';
    const REJECTED         = 'REJECTED';
    const NEEDS_UPDATE     = 'NEEDS_UPDATE';
    const PUBLISHED        = 'PUBLISHED';

    public static function states() {
        return array(
            self::COLLECTING,
            self::READY_FOR_REVIEW,
            self::ACCEPTED,
            self::DRAFT,
            self::REJECTED,
            self::NEEDS_UPDATE,
            self::PUBLISHED,
        );
    }

    public static function normalize($state, $fallback = self::READY_FOR_REVIEW) {
        $state = strtoupper(sanitize_key((string) $state));
        return in_array($state, self::states(), true) ? $state : $fallback;
    }

    public static function from_post_status($post_status, $has_changes = false) {
        $post_status = sanitize_key((string) $post_status);
        if ($has_changes) return self::NEEDS_UPDATE;
        if ($post_status === 'publish') return self::PUBLISHED;
        if (in_array($post_status, array('draft','pending','future','private'), true)) return self::DRAFT;
        return self::READY_FOR_REVIEW;
    }

    public static function can_create_draft($state) {
        return in_array(self::normalize($state), array(
            self::READY_FOR_REVIEW,
            self::ACCEPTED,
            self::NEEDS_UPDATE,
        ), true);
    }

    public static function quality_indicator($pass, $detail) {
        return array(
            'pass'=>(bool) $pass,
            'blocking'=>false,
            'detail'=>sanitize_text_field((string) $detail),
        );
    }
}
