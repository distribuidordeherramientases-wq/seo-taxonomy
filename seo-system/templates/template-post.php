<?php
/**
 * Template Name: Post editorial + conversión DHT
 * Gestor de variante post: móvil / escritorio.
 *
 * DHT POST V1.3 SELF-CONTAINED
 * Política visual: Media -> índice Media -> proveedor -> logo.
 *
 * Post: resuelve una necesidad informativa y deriva a la categoria comercial explicita; no replica el catalogo.
 */

defined('ABSPATH') || exit;

require_once __DIR__ . '/template-helpers.php';

require dht_template_device_variant_file('post');
