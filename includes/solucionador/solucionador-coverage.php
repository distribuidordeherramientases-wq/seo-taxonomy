<?php
/**
 * Compatibilidad histórica de cobertura de Solucionador.
 *
 * La implementación vive en SEO_Editorial_Coverage para que Solucionador,
 * Ingeniero y Comparador puedan compartir el mismo índice sin depender del
 * nombre del servicio que lo creó originalmente.
 */

defined('ABSPATH') || exit;

if (!class_exists('SEO_Editorial_Coverage')) {
    require_once dirname(__DIR__) . '/editorial/seo-editorial-coverage.php';
}

class SEO_Solucionador_Coverage extends SEO_Editorial_Coverage {
    // Wrapper de compatibilidad: no añadir lógica nueva aquí.
}
