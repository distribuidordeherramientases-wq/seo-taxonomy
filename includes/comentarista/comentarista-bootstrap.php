<?php
/**
 * SEO System - Comentarista.
 *
 * Gestion manual de opiniones, comentarios y contenidos externos asociados
 * a productos WooCommerce. El modulo no realiza scraping ni llamadas remotas.
 */

defined('ABSPATH') || exit;

require_once __DIR__ . '/comentarista-db.php';
require_once __DIR__ . '/comentarista-source.php';
require_once __DIR__ . '/comentarista-admin.php';
require_once __DIR__ . '/comentarista-render.php';
require_once __DIR__ . '/comentarista-import-export.php';
