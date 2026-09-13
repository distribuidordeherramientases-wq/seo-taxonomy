<?php
/**
 * Compatibilidad temporal con instalaciones que todavia cargan bootstrap.php.
 *
 * El bootstrap real del proceso Clonador es clonador-bootstrap.php.
 *
 * @deprecated 2.5.10
 */

defined( 'ABSPATH' ) || exit;
require_once __DIR__ . '/clonador-bootstrap.php';
