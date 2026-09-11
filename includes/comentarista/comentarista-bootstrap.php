<?php
/**
 * Bootstrap del subsistema Comentarista.
 */

defined('ABSPATH') || exit;

require_once __DIR__ . '/comentarista-db.php';
require_once __DIR__ . '/comentarista-source.php';
require_once __DIR__ . '/comentarista-health.php';
require_once __DIR__ . '/comentarista-render.php';
require_once __DIR__ . '/comentarista-admin.php';

// Instalacion idempotente para staging y reparacion de la tabla si faltara.
add_action('admin_init', 'seo_comentarista_maybe_install_schema', 18);
