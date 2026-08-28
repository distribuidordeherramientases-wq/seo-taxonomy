<?php
/**
 * SEO System — Registro central de entidades importables/exportables.
 *
 * Responsabilidad:
 * Publicar un catalogo comun de entidades y sus capacidades. Durante el Build
 * 032 los motores historicos siguen ejecutando las operaciones; este registro
 * es el contrato de extension para extraer cada entidad progresivamente sin
 * volver a acoplarla al controlador de la cola.
 *
 * Para ampliar el sistema, use el filtro seo_ie_entity_registry y declare una
 * entidad con label, batch_size y columnas de deteccion. No escriba directamente
 * en la cola desde un modulo nuevo.
 *
 * @package SEOSystem
 * @subpackage ImportExport
 * @since 2.3.0
 * Build: 032
 */

defined( 'ABSPATH' ) || exit;

/**
 * Devuelve las entidades conocidas por Import/Export.
 *
 * @return array<string,array<string,mixed>>
 */
function seo_ie_entity_registry() {
    $entities = [
        'product' => [
            'label'      => 'Productos',
            'batch_size' => 5,
            'required_any' => [ 'product_id', 'sku', 'proveedor_id_externo' ],
            'markers'    => [ 'product_id', 'sku', 'tipo_producto', 'visibilidad_catalogo', 'categorias_ids', 'precio_normal', 'precio_actual', 'estado_stock', 'proveedor_id_externo', 'atributos_wc_json', 'atributos_seo_json', 'galeria_urls', 'tipo_semantico', 'rol', 'ambito', 'aplicacion', 'plataforma', 'subtipo' ],
        ],
        'category' => [
            'label'      => 'Categorias',
            'batch_size' => 0,
            'required'   => [ 'category_id' ],
            'markers'    => [ 'category_id', 'parent_id', 'hub_secondary_id', 'imagen_destacada_id', 'imagen_destacada' ],
        ],
        'page' => [
            'label'      => 'Paginas',
            'batch_size' => 0,
            'required_any' => [ 'page_id', 'ruta' ],
            'markers'    => [ 'page_id', 'ruta', 'parent_ruta', 'parent_slug', 'menu_order', 'plantilla', 'autor_id', 'fecha_gmt', 'meta_seo', 'meta_personalizados', 'comentarios', 'pings' ],
        ],
        'post' => [
            'label'      => 'Entradas',
            'batch_size' => 0,
            'required_any' => [ 'post_id', 'slug', 'url' ],
            'markers'    => [ 'post_id', 'categorias_slugs', 'categorias_nombres', 'etiquetas_ids', 'etiquetas_slugs', 'etiquetas_nombres', 'formato', 'sticky', 'autor_id', 'fecha_gmt', 'meta_seo', 'meta_personalizados' ],
        ],
        'faq' => [
            'label'      => 'FAQs',
            'batch_size' => 0,
            'required'   => [ 'object_type', 'object_id', 'question', 'answer' ],
        ],
        'redirect' => [
            'label'      => 'Redirects',
            'batch_size' => 0,
            'required'   => [ 'origin_url', 'target_url' ],
        ],
    ];

    /**
     * Permite registrar nuevas entidades sin modificar el nucleo.
     *
     * @param array<string,array<string,mixed>> $entities Registro actual.
     */
    return apply_filters( 'seo_ie_entity_registry', $entities );
}

/**
 * Devuelve la etiqueta publica de una entidad.
 *
 * @param string $entity Identificador.
 * @return string
 */
function seo_ie_entity_label( $entity ) {
    $registry = seo_ie_entity_registry();
    $entity   = sanitize_key( $entity );

    return isset( $registry[ $entity ]['label'] )
        ? (string) $registry[ $entity ]['label']
        : 'Desconocido';
}
