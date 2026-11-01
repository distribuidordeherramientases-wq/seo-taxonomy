<?php

defined( 'ABSPATH' ) || exit;

/**
 * Portable JSON backup/transfer for the canonical Vocabulary catalog.
 *
 * Numeric source IDs are exported only for audit. Destination resolution uses
 * portable keys (group+slug, attribute slug, supplier/external id, SKU, slug).
 */
final class TCV_Portable {
    const SCHEMA = 'taxocommerce_vocabulary_portable';
    const VERSION = 1;
    const PREVIEW_TTL = 7200;

    public static function boot() {
        add_action( 'admin_post_tcv_export_portable_json', array( __CLASS__, 'export_download' ) );
        add_action( 'admin_post_tcv_import_portable_json', array( __CLASS__, 'import_request' ) );
    }

    private static function guard() {
        if ( ! current_user_can( 'manage_woocommerce' ) && ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'No tienes permisos para gestionar Vocabulary.', 'taxocommerce-vocabulary' ) );
        }
    }

    public static function allowed_scopes() {
        return array( 'masters', 'product_semantic', 'category_semantic', 'product_attributes', 'product_tags' );
    }

    public static function render_panel() {
        $preview = get_transient( self::preview_key() );
        echo '<div class="tcv-panel" style="margin-top:20px"><h2>Catálogo completo · JSON portable</h2>';
        echo '<p>Copia portable para backup, análisis externo o traslado entre instalaciones. Los IDs numéricos viajan solo como auditoría; el destino se resuelve mediante claves portables.</p>';
        echo '<div class="tcv-grid">';

        echo '<div><h3>Exportar catálogo</h3><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
        echo '<input type="hidden" name="action" value="tcv_export_portable_json">';
        wp_nonce_field( 'tcv_export_portable_json' );
        $labels = array(
            'masters'            => 'Maestros: Vocabulary, TIPO → ROL y atributos',
            'product_semantic'   => 'Producto → Vocabulary',
            'category_semantic'  => 'Categoría → Vocabulary',
            'product_attributes' => 'Producto → atributos canónicos',
            'product_tags'       => 'Producto → etiquetas WooCommerce',
        );
        foreach ( $labels as $scope => $label ) {
            echo '<label style="display:block;margin:6px 0"><input type="checkbox" name="scope[]" value="' . esc_attr( $scope ) . '" checked> ' . esc_html( $label ) . '</label>';
        }
        echo '<p><button class="button button-primary">Descargar JSON completo</button></p></form>';
        echo '<p class="description">No incluye productos completos, imágenes, pedidos, usuarios ni configuración general de WordPress.</p></div>';

        echo '<div><h3>Importar catálogo</h3>';
        echo '<p><strong>La simulación es obligatoria.</strong> Para aplicar debes volver a subir exactamente el mismo archivo y mantener el mismo modo.</p>';
        echo '<form method="post" enctype="multipart/form-data" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
        echo '<input type="hidden" name="action" value="tcv_import_portable_json">';
        wp_nonce_field( 'tcv_import_portable_json' );
        echo '<p><input type="file" name="portable_json" accept=".json,application/json" required></p>';
        echo '<p><label><strong>Modo</strong><br><select name="portable_mode">';
        echo '<option value="merge">MERGE · añadir/actualizar sin retirar asignaciones locales</option>';
        echo '<option value="mirror">MIRROR · igualar asignaciones de los objetos incluidos</option>';
        echo '</select></label></p>';
        echo '<p><button class="button button-primary" name="portable_intent" value="preview">1. Simular importación</button></p>';
        echo '<p><button class="button" name="portable_intent" value="apply">2. Aplicar archivo simulado</button></p>';
        echo '</form>';
        if ( is_array( $preview ) && ! empty( $preview['summary'] ) ) {
            echo '<p class="description"><strong>Última simulación:</strong> ' . esc_html( wp_json_encode( $preview['summary'], JSON_UNESCAPED_UNICODE ) ) . '</p>';
        }
        echo '<p class="description">MIRROR sustituye las asignaciones incluidas de productos/categorías y sus atributos; por seguridad no borra maestros canónicos que no estén en el paquete.</p></div>';
        echo '</div></div>';
    }

    private static function requested_scopes() {
        // Verify the export form within the input-reading method as well as its caller.
        check_admin_referer( 'tcv_export_portable_json' );
        $raw = isset( $_POST['scope'] ) && is_array( $_POST['scope'] )
            ? array_map( 'sanitize_key', wp_unslash( $_POST['scope'] ) )
            : self::allowed_scopes();
        return array_values( array_intersect( self::allowed_scopes(), array_unique( $raw ) ) );
    }

    private static function canonical_json( $value ) {
        return wp_json_encode( $value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE );
    }

    private static function fingerprint( $value ) {
        return hash( 'sha256', (string) self::canonical_json( $value ) );
    }

    public static function export_download() {
        self::guard();
        check_admin_referer( 'tcv_export_portable_json' );
        $scopes = self::requested_scopes();
        if ( ! $scopes ) {
            wp_die( 'Selecciona al menos una capa.' );
        }

        $document = self::build_document( $scopes );
        if ( is_wp_error( $document ) ) {
            wp_die( esc_html( $document->get_error_message() ) );
        }

        $json = wp_json_encode( $document, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE );
        if ( ! is_string( $json ) || '' === $json ) {
            wp_die( 'No se pudo generar el catálogo portable.' );
        }

        nocache_headers();
        header( 'X-Content-Type-Options: nosniff' );
        header( 'Content-Type: application/json; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename="taxocommerce-vocabulary-' . gmdate( 'Ymd-His' ) . '.json"' );
        echo $json; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        exit;
    }

    public static function build_document( $scopes ) {
        $scopes = array_values( array_intersect( self::allowed_scopes(), (array) $scopes ) );
        $sections = array();

        if ( in_array( 'masters', $scopes, true ) ) {
            $sections['masters'] = self::export_masters();
        }
        if ( in_array( 'product_semantic', $scopes, true ) || in_array( 'product_attributes', $scopes, true ) || in_array( 'product_tags', $scopes, true ) ) {
            $sections['products'] = self::export_products( $scopes );
        }
        if ( in_array( 'category_semantic', $scopes, true ) ) {
            $sections['categories'] = self::export_categories();
        }

        $integrity = self::integrity_summary();
        return array(
            'schema' => array(
                'name'    => self::SCHEMA,
                'version' => self::VERSION,
            ),
            'manifest' => array(
                'plugin_version'  => defined( 'TAXOCOMMERCE_VOCABULARY_VERSION' ) ? TAXOCOMMERCE_VOCABULARY_VERSION : '',
                'generated_at'    => gmdate( 'c' ),
                'source_home_url' => home_url( '/' ),
                'environment'     => function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : '',
                'scope'           => $scopes,
                'identity_policy' => 'numeric_ids_audit_only',
                'fingerprint'     => self::fingerprint( $sections ),
                'counts'          => self::counts( $sections ),
            ),
            'integrity_summary' => $integrity,
            'sections'          => $sections,
        );
    }

    // Portable export, identity resolution and MERGE/MIRROR transactions require the live shared tables; no cross-module cache invalidation exists.
    // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    private static function export_masters() {
        global $wpdb;
        $t = TCV_Schema::tables();

        $vocabulary = $wpdb->get_results(
            $wpdb->prepare( "SELECT id,semantic_group,slug,label,parent_id,source,active
             FROM %i
             ORDER BY semantic_group,slug", $t['vocabulary'] ),
            ARRAY_A
        );
        $type_roles = $wpdb->get_results(
            $wpdb->prepare( "SELECT tv.slug AS type_slug,rv.slug AS role_slug,m.confidence,m.source,m.active
             FROM %i m
             INNER JOIN %i tv ON tv.id=m.type_vocabulary_id
             INNER JOIN %i rv ON rv.id=m.role_vocabulary_id
             ORDER BY tv.slug", $t['type_role'], $t['vocabulary'], $t['vocabulary'] ),
            ARRAY_A
        );
        $attributes = $wpdb->get_results(
            $wpdb->prepare( "SELECT id,slug,nombre,grupo,tipo,unidad_tipo,unidad_base,multiple,filtrable,visible,seo,orden,activo
             FROM %i ORDER BY orden,slug", $t['attributes'] ),
            ARRAY_A
        );
        $terms = $wpdb->get_results(
            $wpdb->prepare( "SELECT a.slug AS attribute_slug,t.id,t.slug,t.nombre,t.orden,t.activo
             FROM %i t INNER JOIN %i a ON a.id=t.atributo_id
             ORDER BY a.slug,t.orden,t.slug", $t['terms'], $t['attributes'] ),
            ARRAY_A
        );
        $aliases = $wpdb->get_results(
            $wpdb->prepare( "SELECT a.slug AS attribute_slug,t.slug AS term_slug,x.alias
             FROM %i x
             INNER JOIN %i a ON a.id=x.atributo_id
             LEFT JOIN %i t ON t.id=x.termino_id
             ORDER BY a.slug,t.slug,x.alias", $t['aliases'], $t['attributes'], $t['terms'] ),
            ARRAY_A
        );

        $id_to_key = array();
        foreach ( (array) $vocabulary as $row ) {
            $id_to_key[ absint( $row['id'] ) ] = sanitize_key( $row['semantic_group'] ) . ':' . sanitize_title( $row['slug'] );
        }
        foreach ( $vocabulary as &$row ) {
            $row['source_id'] = absint( $row['id'] );
            $row['parent_key'] = ! empty( $row['parent_id'] ) && isset( $id_to_key[ absint( $row['parent_id'] ) ] ) ? $id_to_key[ absint( $row['parent_id'] ) ] : '';
            unset( $row['id'], $row['parent_id'] );
        }
        unset( $row );

        foreach ( $attributes as &$row ) {
            $row['source_id'] = absint( $row['id'] );
            unset( $row['id'] );
        }
        unset( $row );
        foreach ( $terms as &$row ) {
            $row['source_id'] = absint( $row['id'] );
            unset( $row['id'] );
        }
        unset( $row );

        return array(
            'vocabulary'       => array_values( $vocabulary ),
            'type_role_map'    => array_values( $type_roles ),
            'attributes'       => array_values( $attributes ),
            'attribute_terms'  => array_values( $terms ),
            'attribute_aliases'=> array_values( $aliases ),
        );
    }

    // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    private static function product_identity( $product_id ) {
        $post = get_post( $product_id );
        return array(
            'source_id'            => absint( $product_id ),
            'provider'             => sanitize_text_field( (string) get_post_meta( $product_id, '_seo_proveedor', true ) ),
            'provider_external_id' => sanitize_text_field( (string) get_post_meta( $product_id, '_seo_proveedor_id_externo', true ) ),
            'sku'                  => sanitize_text_field( (string) get_post_meta( $product_id, '_sku', true ) ),
            'slug'                 => $post ? sanitize_title( $post->post_name ) : '',
        );
    }

    // Portable export, identity resolution and MERGE/MIRROR transactions require the live shared tables; no cross-module cache invalidation exists.
    // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    private static function export_products( $scopes ) {
        global $wpdb;
        $t = TCV_Schema::tables();
        $ids = array();

        if ( in_array( 'product_semantic', $scopes, true ) ) {
            $ids = array_merge( $ids, (array) $wpdb->get_col(
                $wpdb->prepare( "SELECT DISTINCT object_id FROM %i WHERE object_type='product'", $t['objects'] )
            ) );
        }
        if ( in_array( 'product_attributes', $scopes, true ) ) {
            $ids = array_merge( $ids, (array) $wpdb->get_col(
                $wpdb->prepare( "SELECT DISTINCT product_id FROM %i", $t['product_att'] )
            ) );
        }
        if ( in_array( 'product_tags', $scopes, true ) ) {
            $ids = array_merge( $ids, (array) $wpdb->get_col(
                "SELECT DISTINCT tr.object_id
                 FROM {$wpdb->term_relationships} tr
                 INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id=tr.term_taxonomy_id
                 INNER JOIN {$wpdb->posts} p ON p.ID=tr.object_id
                 WHERE tt.taxonomy='product_tag' AND p.post_type='product'"
            ) );
        }

        $ids = array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );
        sort( $ids, SORT_NUMERIC );
        $out = array();

        foreach ( $ids as $product_id ) {
            if ( 'product' !== get_post_type( $product_id ) ) {
                continue;
            }
            $row = array( 'identity' => self::product_identity( $product_id ) );

            if ( in_array( 'product_semantic', $scopes, true ) ) {
                $row['semantic'] = $wpdb->get_results(
                    $wpdb->prepare(
                        "SELECT v.semantic_group,v.slug,v.label,ov.source,ov.confidence,ov.status
                         FROM %i ov
                         INNER JOIN %i v ON v.id=ov.vocabulary_id
                         WHERE ov.object_type='product' AND ov.object_id=%d
                         ORDER BY v.semantic_group,v.slug", $t['objects'], $t['vocabulary'],
                        $product_id
                    ),
                    ARRAY_A
                );
            }

            if ( in_array( 'product_attributes', $scopes, true ) ) {
                $row['attributes'] = $wpdb->get_results(
                    $wpdb->prepare(
                        "SELECT a.slug AS attribute_slug,t.slug AS term_slug,pa.valor_texto,pa.valor_numero,pa.valor_numero_max,pa.unidad,pa.valor_original,pa.orden
                         FROM %i pa
                         INNER JOIN %i a ON a.id=pa.atributo_id
                         LEFT JOIN %i t ON t.id=pa.termino_id
                         WHERE pa.product_id=%d
                         ORDER BY pa.orden,pa.id", $t['product_att'], $t['attributes'], $t['terms'],
                        $product_id
                    ),
                    ARRAY_A
                );
            }

            if ( in_array( 'product_tags', $scopes, true ) ) {
                $tags = wp_get_post_terms( $product_id, 'product_tag', array( 'fields' => 'all' ) );
                $row['product_tags'] = array();
                if ( ! is_wp_error( $tags ) ) {
                    foreach ( $tags as $tag ) {
                        $row['product_tags'][] = array( 'slug' => $tag->slug, 'name' => $tag->name );
                    }
                }
            }
            $out[] = $row;
        }
        return $out;
    }

    // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    private static function category_identity( $term ) {
        $path = array();
        $parent = absint( $term->parent );
        $guard = 0;
        while ( $parent && $guard++ < 30 ) {
            $p = get_term( $parent, 'product_cat' );
            if ( ! $p || is_wp_error( $p ) ) {
                break;
            }
            array_unshift( $path, $p->slug );
            $parent = absint( $p->parent );
        }
        return array(
            'source_id' => absint( $term->term_id ),
            'taxonomy'  => 'product_cat',
            'slug'      => $term->slug,
            'path_slugs'=> $path,
        );
    }

    // Portable export, identity resolution and MERGE/MIRROR transactions require the live shared tables; no cross-module cache invalidation exists.
    // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    private static function export_categories() {
        global $wpdb;
        $t = TCV_Schema::tables();
        $ids = (array) $wpdb->get_col(
            $wpdb->prepare( "SELECT DISTINCT object_id FROM %i WHERE object_type='product_cat' ORDER BY object_id", $t['objects'] )
        );
        $out = array();
        foreach ( $ids as $term_id ) {
            $term = get_term( absint( $term_id ), 'product_cat' );
            if ( ! $term || is_wp_error( $term ) ) {
                continue;
            }
            $semantic = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT v.semantic_group,v.slug,v.label,ov.source,ov.confidence,ov.status
                     FROM %i ov
                     INNER JOIN %i v ON v.id=ov.vocabulary_id
                     WHERE ov.object_type='product_cat' AND ov.object_id=%d
                     ORDER BY v.semantic_group,v.slug", $t['objects'], $t['vocabulary'],
                    $term->term_id
                ),
                ARRAY_A
            );
            $out[] = array(
                'identity' => self::category_identity( $term ),
                'semantic' => $semantic,
            );
        }
        return $out;
    }

    // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    private static function counts( $sections ) {
        return array(
            'vocabulary'  => count( (array) ( $sections['masters']['vocabulary'] ?? array() ) ),
            'type_role'   => count( (array) ( $sections['masters']['type_role_map'] ?? array() ) ),
            'attributes'  => count( (array) ( $sections['masters']['attributes'] ?? array() ) ),
            'attribute_terms' => count( (array) ( $sections['masters']['attribute_terms'] ?? array() ) ),
            'attribute_aliases' => count( (array) ( $sections['masters']['attribute_aliases'] ?? array() ) ),
            'products'    => count( (array) ( $sections['products'] ?? array() ) ),
            'categories'  => count( (array) ( $sections['categories'] ?? array() ) ),
        );
    }

    // Portable export, identity resolution and MERGE/MIRROR transactions require the live shared tables; no cross-module cache invalidation exists.
    // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    public static function integrity_summary() {
        global $wpdb;
        $t = TCV_Schema::tables();
        $groups = $wpdb->get_results(
            $wpdb->prepare( "SELECT semantic_group,COUNT(*) n FROM %i WHERE active=1 GROUP BY semantic_group", $t['vocabulary'] ),
            OBJECT_K
        );
        $orphans = (int) $wpdb->get_var(
            $wpdb->prepare( "SELECT COUNT(*) FROM %i ov LEFT JOIN %i v ON v.id=ov.vocabulary_id WHERE v.id IS NULL", $t['objects'], $t['vocabulary'] )
        );
        $inactive_relations = (int) $wpdb->get_var(
            $wpdb->prepare( "SELECT COUNT(*) FROM %i ov INNER JOIN %i v ON v.id=ov.vocabulary_id WHERE ov.status=1 AND v.active=0", $t['objects'], $t['vocabulary'] )
        );
        $types_without_role = (int) $wpdb->get_var(
            $wpdb->prepare( "SELECT COUNT(*)
             FROM %i v
             LEFT JOIN %i m ON m.type_vocabulary_id=v.id AND m.active=1
             WHERE v.semantic_group='tipo' AND v.active=1 AND m.id IS NULL", $t['vocabulary'], $t['type_role'] )
        );
        return array(
            'groups' => array(
                'tipo'       => (int) ( $groups['tipo']->n ?? 0 ),
                'rol'        => (int) ( $groups['rol']->n ?? 0 ),
                'aplicacion' => (int) ( $groups['aplicacion']->n ?? 0 ),
                'plataforma' => (int) ( $groups['plataforma']->n ?? 0 ),
                'subtipo'    => (int) ( $groups['subtipo']->n ?? 0 ),
            ),
            'object_relations'        => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE status=1", $t['objects'] ) ),
            'product_attribute_rows'  => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i", $t['product_att'] ) ),
            'orphan_relations'        => $orphans,
            'inactive_term_relations' => $inactive_relations,
            'active_types_without_role'=> $types_without_role,
        );
    }

    // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    private static function read_uploaded_document() {
        // The only caller is nonce-protected; check here too so the upload
        // cannot be read through a future unprotected caller.
        check_admin_referer( 'tcv_import_portable_json' );
        // An uploaded temporary path must remain unchanged, so validate its
        // PHP origin and upload error rather than rewriting the path.
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- PHP's uploaded-file metadata must remain unmodified; validated with UPLOAD_ERR_OK, is_uploaded_file() and size.
        $upload = isset( $_FILES['portable_json'] ) && is_array( $_FILES['portable_json'] ) ? $_FILES['portable_json'] : array();
        $tmp_path = isset( $upload['tmp_name'] ) && is_string( $upload['tmp_name'] ) ? $upload['tmp_name'] : '';
        $upload_error = isset( $upload['error'] ) ? absint( $upload['error'] ) : UPLOAD_ERR_NO_FILE;
        if ( UPLOAD_ERR_OK !== $upload_error || '' === $tmp_path || ! is_uploaded_file( $tmp_path ) ) {
            return new WP_Error( 'tcv_portable_missing', 'No se ha recibido ningún JSON.' );
        }
        $size = absint( $upload['size'] ?? 0 );
        if ( $size < 2 || $size > 268435456 ) {
            return new WP_Error( 'tcv_portable_size', 'El archivo está vacío o supera 256 MiB.' );
        }
        $raw = file_get_contents( $tmp_path );
        if ( ! is_string( $raw ) || '' === $raw ) {
            return new WP_Error( 'tcv_portable_read', 'No se pudo leer el archivo.' );
        }
        $document = json_decode( $raw, true );
        if ( ! is_array( $document ) ) {
            return new WP_Error( 'tcv_portable_json', 'El archivo no contiene JSON válido.' );
        }
        $valid = self::validate_document( $document );
        if ( is_wp_error( $valid ) ) {
            return $valid;
        }
        return array( 'document' => $document, 'hash' => hash( 'sha256', $raw ) );
    }

    private static function validate_document( $document ) {
        if ( (string) ( $document['schema']['name'] ?? '' ) !== self::SCHEMA || absint( $document['schema']['version'] ?? 0 ) !== self::VERSION ) {
            return new WP_Error( 'tcv_portable_schema', 'Esquema de catálogo portable no compatible.' );
        }
        if ( empty( $document['sections'] ) || ! is_array( $document['sections'] ) ) {
            return new WP_Error( 'tcv_portable_sections', 'El paquete no contiene secciones.' );
        }
        return true;
    }

    private static function preview_key() {
        return 'tcv_portable_preview_' . get_current_user_id();
    }

    public static function import_request() {
        self::guard();
        check_admin_referer( 'tcv_import_portable_json' );
        $loaded = self::read_uploaded_document();
        if ( is_wp_error( $loaded ) ) {
            self::redirect( 'error', $loaded->get_error_message() );
        }

        $mode = isset( $_POST['portable_mode'] ) ? sanitize_key( wp_unslash( $_POST['portable_mode'] ) ) : 'merge';
        if ( ! in_array( $mode, array( 'merge', 'mirror' ), true ) ) {
            $mode = 'merge';
        }
        $intent = isset( $_POST['portable_intent'] ) ? sanitize_key( wp_unslash( $_POST['portable_intent'] ) ) : 'preview';
        $plan = self::analyze_document( $loaded['document'], $mode );

        if ( 'preview' === $intent ) {
            set_transient(
                self::preview_key(),
                array(
                    'hash'        => $loaded['hash'],
                    'mode'        => $mode,
                    'fingerprint' => $plan['plan_fingerprint'],
                    'summary'     => $plan['summary'],
                    'conflicts'   => $plan['conflicts'],
                ),
                self::PREVIEW_TTL
            );
            $message = sprintf(
                'Simulación completada: %d objetos resolubles, %d conflictos. No se ha escrito ningún dato.',
                absint( $plan['summary']['resolved_objects'] ?? 0 ),
                count( $plan['conflicts'] )
            );
            self::redirect( count( $plan['conflicts'] ) ? 'error' : 'success', $message );
        }

        $preview = get_transient( self::preview_key() );
        if ( ! is_array( $preview ) || ! hash_equals( (string) ( $preview['hash'] ?? '' ), (string) $loaded['hash'] ) || (string) ( $preview['mode'] ?? '' ) !== $mode ) {
            self::redirect( 'error', 'Debes simular exactamente este mismo archivo y modo antes de aplicarlo.' );
        }
        if ( ! empty( $plan['conflicts'] ) ) {
            self::redirect( 'error', 'La importación contiene conflictos y no se ha aplicado.' );
        }
        if ( ! hash_equals( (string) ( $preview['fingerprint'] ?? '' ), (string) $plan['plan_fingerprint'] ) ) {
            self::redirect( 'error', 'El destino ha cambiado desde la simulación. Repite la simulación.' );
        }

        $result = self::apply_document( $loaded['document'], $mode );
        if ( is_wp_error( $result ) ) {
            self::redirect( 'error', $result->get_error_message() );
        }
        delete_transient( self::preview_key() );
        self::redirect(
            'success',
            sprintf(
                'Catálogo aplicado: %d maestros actualizados/creados, %d objetos procesados.',
                absint( $result['masters'] ),
                absint( $result['objects'] )
            )
        );
    }

    private static function analyze_document( $document, $mode ) {
        $conflicts = array();
        $resolved = 0;

        foreach ( (array) ( $document['sections']['products'] ?? array() ) as $row ) {
            $id = self::resolve_product( (array) ( $row['identity'] ?? array() ) );
            if ( is_wp_error( $id ) ) {
                $conflicts[] = 'Producto: ' . $id->get_error_message();
            } else {
                $resolved++;
            }
        }
        foreach ( (array) ( $document['sections']['categories'] ?? array() ) as $row ) {
            $id = self::resolve_category( (array) ( $row['identity'] ?? array() ) );
            if ( is_wp_error( $id ) ) {
                $conflicts[] = 'Categoría: ' . $id->get_error_message();
            } else {
                $resolved++;
            }
        }

        $summary = array(
            'mode'             => $mode,
            'resolved_objects' => $resolved,
            'products'         => count( (array) ( $document['sections']['products'] ?? array() ) ),
            'categories'       => count( (array) ( $document['sections']['categories'] ?? array() ) ),
            'conflicts'        => count( $conflicts ),
        );
        $state = array(
            'document_fingerprint' => (string) ( $document['manifest']['fingerprint'] ?? self::fingerprint( $document['sections'] ) ),
            'summary'              => $summary,
            'conflicts'            => $conflicts,
            'destination_integrity'=> self::integrity_summary(),
        );
        return array(
            'summary'          => $summary,
            'conflicts'        => $conflicts,
            'plan_fingerprint' => self::fingerprint( $state ),
        );
    }

    // Portable export, identity resolution and MERGE/MIRROR transactions require the live shared tables; no cross-module cache invalidation exists.
    // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    private static function resolve_product( $identity ) {
        global $wpdb;
        $provider = sanitize_text_field( (string) ( $identity['provider'] ?? '' ) );
        $external = sanitize_text_field( (string) ( $identity['provider_external_id'] ?? '' ) );
        $sku = sanitize_text_field( (string) ( $identity['sku'] ?? '' ) );
        $slug = sanitize_title( (string) ( $identity['slug'] ?? '' ) );

        if ( '' !== $provider && '' !== $external ) {
            $ids = $wpdb->get_col(
                $wpdb->prepare(
                    "SELECT DISTINCT p.ID
                     FROM {$wpdb->posts} p
                     INNER JOIN {$wpdb->postmeta} pe ON pe.post_id=p.ID AND pe.meta_key='_seo_proveedor_id_externo' AND pe.meta_value=%s
                     INNER JOIN {$wpdb->postmeta} pp ON pp.post_id=p.ID AND pp.meta_key='_seo_proveedor' AND pp.meta_value=%s
                     WHERE p.post_type='product' AND p.post_status<>'trash' LIMIT 2",
                    $external,
                    $provider
                )
            );
            if ( 1 === count( $ids ) ) {
                return absint( $ids[0] );
            }
            if ( count( $ids ) > 1 ) {
                return new WP_Error( 'tcv_product_ambiguous_provider', 'proveedor + id externo no es único.' );
            }
        }
        if ( '' !== $sku ) {
            $ids = $wpdb->get_col(
                $wpdb->prepare(
                    "SELECT DISTINCT p.ID FROM {$wpdb->posts} p
                     INNER JOIN {$wpdb->postmeta} pm ON pm.post_id=p.ID AND pm.meta_key='_sku' AND pm.meta_value=%s
                     WHERE p.post_type='product' AND p.post_status<>'trash' LIMIT 2",
                    $sku
                )
            );
            if ( 1 === count( $ids ) ) {
                return absint( $ids[0] );
            }
            if ( count( $ids ) > 1 ) {
                return new WP_Error( 'tcv_product_ambiguous_sku', 'SKU duplicado: ' . $sku );
            }
        }
        if ( '' !== $slug ) {
            $post = get_page_by_path( $slug, OBJECT, 'product' );
            if ( $post && 'trash' !== $post->post_status ) {
                return absint( $post->ID );
            }
        }
        return new WP_Error( 'tcv_product_unresolved', 'no se pudo resolver ' . ( $sku ?: $external ?: $slug ?: 'sin identidad portable' ) );
    }

    // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    private static function resolve_category( $identity ) {
        $slug = sanitize_title( (string) ( $identity['slug'] ?? '' ) );
        if ( '' === $slug ) {
            return new WP_Error( 'tcv_category_missing_slug', 'slug vacío.' );
        }
        $term = get_term_by( 'slug', $slug, 'product_cat' );
        if ( ! $term || is_wp_error( $term ) ) {
            return new WP_Error( 'tcv_category_unresolved', 'no existe product_cat/' . $slug );
        }

        $expected = array_values( array_filter( array_map( 'sanitize_title', (array) ( $identity['path_slugs'] ?? array() ) ) ) );
        if ( $expected ) {
            $actual = self::category_identity( $term );
            if ( $expected !== (array) $actual['path_slugs'] ) {
                return new WP_Error( 'tcv_category_path_conflict', 'la ruta de ' . $slug . ' no coincide.' );
            }
        }
        return absint( $term->term_id );
    }

    // Portable export, identity resolution and MERGE/MIRROR transactions require the live shared tables; no cross-module cache invalidation exists.
    // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    private static function vocabulary_id( $group, $slug ) {
        global $wpdb;
        $t = TCV_Schema::tables();
        return absint(
            $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT id FROM %i WHERE semantic_group=%s AND slug=%s LIMIT 1", $t['vocabulary'],
                    sanitize_key( $group ),
                    sanitize_title( $slug )
                )
            )
        );
    }

    // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    // Portable export, identity resolution and MERGE/MIRROR transactions require the live shared tables; no cross-module cache invalidation exists.
    // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    private static function attribute_id( $slug ) {
        global $wpdb;
        $t = TCV_Schema::tables();
        return absint( $wpdb->get_var( $wpdb->prepare( "SELECT id FROM %i WHERE slug=%s LIMIT 1", $t['attributes'], sanitize_title( $slug ) ) ) );
    }

    // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    // Portable export, identity resolution and MERGE/MIRROR transactions require the live shared tables; no cross-module cache invalidation exists.
    // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    private static function attribute_term_id( $attribute_id, $slug ) {
        global $wpdb;
        $t = TCV_Schema::tables();
        if ( ! $attribute_id || ! $slug ) {
            return 0;
        }
        return absint( $wpdb->get_var( $wpdb->prepare( "SELECT id FROM %i WHERE atributo_id=%d AND slug=%s LIMIT 1", $t['terms'], $attribute_id, sanitize_title( $slug ) ) ) );
    }

    // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    // Portable export, identity resolution and MERGE/MIRROR transactions require the live shared tables; no cross-module cache invalidation exists.
    // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    private static function apply_document( $document, $mode ) {
        global $wpdb;
        $t = TCV_Schema::tables();
        $masters_count = 0;
        $objects_count = 0;

        $wpdb->query( 'START TRANSACTION' );
        try {
            $masters = (array) ( $document['sections']['masters'] ?? array() );
            if ( $masters ) {
                foreach ( (array) ( $masters['vocabulary'] ?? array() ) as $row ) {
                    $group = sanitize_key( $row['semantic_group'] ?? '' );
                    $slug = sanitize_title( $row['slug'] ?? '' );
                    $label = sanitize_text_field( $row['label'] ?? '' );
                    if ( ! in_array( $group, TCV_Classifier::GROUPS, true ) || ! $slug || ! $label ) {
                        continue;
                    }
                    $wpdb->query(
                        $wpdb->prepare(
                            "INSERT INTO %i (semantic_group,slug,label,source,active)
                             VALUES (%s,%s,%s,%s,%d)
                             ON DUPLICATE KEY UPDATE label=VALUES(label),source=VALUES(source),active=VALUES(active),updated_at=CURRENT_TIMESTAMP", $t['vocabulary'],
                            $group,
                            $slug,
                            $label,
                            sanitize_key( $row['source'] ?? 'portable' ),
                            empty( $row['active'] ) ? 0 : 1
                        )
                    );
                    $masters_count++;
                }

                foreach ( (array) ( $masters['vocabulary'] ?? array() ) as $row ) {
                    $key = (string) ( $row['parent_key'] ?? '' );
                    if ( ! $key || false === strpos( $key, ':' ) ) {
                        continue;
                    }
                    list( $pg, $ps ) = explode( ':', $key, 2 );
                    $child = self::vocabulary_id( $row['semantic_group'] ?? '', $row['slug'] ?? '' );
                    $parent = self::vocabulary_id( $pg, $ps );
                    if ( $child && $parent ) {
                        $wpdb->update( $t['vocabulary'], array( 'parent_id' => $parent ), array( 'id' => $child ) );
                    }
                }

                foreach ( (array) ( $masters['type_role_map'] ?? array() ) as $row ) {
                    $type = self::vocabulary_id( 'tipo', $row['type_slug'] ?? '' );
                    $role = self::vocabulary_id( 'rol', $row['role_slug'] ?? '' );
                    if ( $type && $role ) {
                        $wpdb->query(
                            $wpdb->prepare(
                                "INSERT INTO %i (type_vocabulary_id,role_vocabulary_id,confidence,source,active)
                                 VALUES (%d,%d,%f,%s,%d)
                                 ON DUPLICATE KEY UPDATE role_vocabulary_id=VALUES(role_vocabulary_id),confidence=VALUES(confidence),source=VALUES(source),active=VALUES(active)", $t['type_role'],
                                $type,
                                $role,
                                max( 0, min( 1, (float) ( $row['confidence'] ?? 1 ) ) ),
                                sanitize_key( $row['source'] ?? 'portable' ),
                                empty( $row['active'] ) ? 0 : 1
                            )
                        );
                        $masters_count++;
                    }
                }

                foreach ( (array) ( $masters['attributes'] ?? array() ) as $row ) {
                    $slug = sanitize_title( $row['slug'] ?? '' );
                    $name = sanitize_text_field( $row['nombre'] ?? '' );
                    if ( ! $slug || ! $name ) {
                        continue;
                    }
                    $type = in_array( $row['tipo'] ?? '', array( 'texto','numero','boolean','termino','rango' ), true ) ? $row['tipo'] : 'texto';
                    $wpdb->query(
                        $wpdb->prepare(
                            "INSERT INTO %i (slug,nombre,grupo,tipo,unidad_tipo,unidad_base,multiple,filtrable,visible,seo,orden,activo)
                             VALUES (%s,%s,%s,%s,%s,%s,%d,%d,%d,%d,%d,%d)
                             ON DUPLICATE KEY UPDATE nombre=VALUES(nombre),grupo=VALUES(grupo),tipo=VALUES(tipo),unidad_tipo=VALUES(unidad_tipo),unidad_base=VALUES(unidad_base),multiple=VALUES(multiple),filtrable=VALUES(filtrable),visible=VALUES(visible),seo=VALUES(seo),orden=VALUES(orden),activo=VALUES(activo)", $t['attributes'],
                            $slug, $name, sanitize_text_field( $row['grupo'] ?? '' ), $type,
                            sanitize_text_field( $row['unidad_tipo'] ?? '' ), sanitize_text_field( $row['unidad_base'] ?? '' ),
                            empty( $row['multiple'] ) ? 0 : 1, empty( $row['filtrable'] ) ? 0 : 1,
                            empty( $row['visible'] ) ? 0 : 1, empty( $row['seo'] ) ? 0 : 1,
                            intval( $row['orden'] ?? 0 ), empty( $row['activo'] ) ? 0 : 1
                        )
                    );
                    $masters_count++;
                }

                foreach ( (array) ( $masters['attribute_terms'] ?? array() ) as $row ) {
                    $aid = self::attribute_id( $row['attribute_slug'] ?? '' );
                    $slug = sanitize_title( $row['slug'] ?? '' );
                    $name = sanitize_text_field( $row['nombre'] ?? '' );
                    if ( ! $aid || ! $slug || ! $name ) {
                        continue;
                    }
                    $wpdb->query(
                        $wpdb->prepare(
                            "INSERT INTO %i (atributo_id,slug,nombre,orden,activo)
                             VALUES (%d,%s,%s,%d,%d)
                             ON DUPLICATE KEY UPDATE nombre=VALUES(nombre),orden=VALUES(orden),activo=VALUES(activo)", $t['terms'],
                            $aid, $slug, $name, intval( $row['orden'] ?? 0 ), empty( $row['activo'] ) ? 0 : 1
                        )
                    );
                    $masters_count++;
                }

                foreach ( (array) ( $masters['attribute_aliases'] ?? array() ) as $row ) {
                    $aid = self::attribute_id( $row['attribute_slug'] ?? '' );
                    $term = self::attribute_term_id( $aid, $row['term_slug'] ?? '' );
                    $alias = sanitize_text_field( $row['alias'] ?? '' );
                    if ( ! $aid || ! $alias ) {
                        continue;
                    }
                    $exists = $wpdb->get_var(
                        $wpdb->prepare(
                            "SELECT id FROM %i WHERE atributo_id=%d AND COALESCE(termino_id,0)=%d AND alias=%s LIMIT 1", $t['aliases'],
                            $aid, $term, $alias
                        )
                    );
                    if ( ! $exists ) {
                        $wpdb->insert( $t['aliases'], array( 'atributo_id'=>$aid, 'termino_id'=>$term ?: null, 'alias'=>$alias ) );
                    }
                    $masters_count++;
                }
            }

            foreach ( (array) ( $document['sections']['products'] ?? array() ) as $row ) {
                $product_id = self::resolve_product( (array) ( $row['identity'] ?? array() ) );
                if ( is_wp_error( $product_id ) ) {
                    throw new RuntimeException( $product_id->get_error_message() );
                }

                if ( array_key_exists( 'semantic', $row ) ) {
                    if ( 'mirror' === $mode ) {
                        $wpdb->delete( $t['objects'], array( 'object_type'=>'product', 'object_id'=>$product_id ) );
                    }
                    foreach ( (array) $row['semantic'] as $semantic ) {
                        $vid = self::vocabulary_id( $semantic['semantic_group'] ?? '', $semantic['slug'] ?? '' );
                        if ( ! $vid ) {
                            continue;
                        }
                        $wpdb->query(
                            $wpdb->prepare(
                                "INSERT INTO %i (object_type,object_id,vocabulary_id,source,confidence,status)
                                 VALUES ('product',%d,%d,%s,%f,%d)
                                 ON DUPLICATE KEY UPDATE source=VALUES(source),confidence=VALUES(confidence),status=VALUES(status),updated_at=CURRENT_TIMESTAMP", $t['objects'],
                                $product_id, $vid, sanitize_key( $semantic['source'] ?? 'portable' ),
                                max( 0, min( 1, (float) ( $semantic['confidence'] ?? 1 ) ) ),
                                empty( $semantic['status'] ) ? 0 : 1
                            )
                        );
                    }
                }

                if ( array_key_exists( 'attributes', $row ) ) {
                    if ( 'mirror' === $mode ) {
                        $wpdb->delete( $t['product_att'], array( 'product_id'=>$product_id ) );
                    }
                    $cleared_attributes = array();
                    foreach ( (array) $row['attributes'] as $attribute ) {
                        $aid = self::attribute_id( $attribute['attribute_slug'] ?? '' );
                        if ( ! $aid ) {
                            continue;
                        }
                        $term_id = self::attribute_term_id( $aid, $attribute['term_slug'] ?? '' );
                        if ( 'merge' === $mode && empty( $cleared_attributes[ $aid ] ) ) {
                            $wpdb->delete( $t['product_att'], array( 'product_id'=>$product_id, 'atributo_id'=>$aid ) );
                            $cleared_attributes[ $aid ] = true;
                        }
                        $wpdb->insert(
                            $t['product_att'],
                            array(
                                'product_id'       => $product_id,
                                'atributo_id'      => $aid,
                                'termino_id'       => $term_id ?: null,
                                'valor_texto'      => sanitize_textarea_field( (string) ( $attribute['valor_texto'] ?? '' ) ),
                                'valor_numero'     => is_numeric( $attribute['valor_numero'] ?? null ) ? (float) $attribute['valor_numero'] : null,
                                'valor_numero_max' => is_numeric( $attribute['valor_numero_max'] ?? null ) ? (float) $attribute['valor_numero_max'] : null,
                                'unidad'           => sanitize_text_field( (string) ( $attribute['unidad'] ?? '' ) ),
                                'valor_original'   => sanitize_textarea_field( (string) ( $attribute['valor_original'] ?? '' ) ),
                                'orden'            => intval( $attribute['orden'] ?? 0 ),
                            )
                        );
                    }
                }

                if ( array_key_exists( 'product_tags', $row ) ) {
                    $tag_names = array_values( array_filter( array_map(
                        static function ( $tag ) { return sanitize_text_field( (string) ( $tag['name'] ?? '' ) ); },
                        (array) $row['product_tags']
                    ) ) );
                    $set = wp_set_object_terms( $product_id, $tag_names, 'product_tag', 'merge' === $mode );
                    if ( is_wp_error( $set ) ) {
                        throw new RuntimeException( $set->get_error_message() );
                    }
                }
                $objects_count++;
            }

            foreach ( (array) ( $document['sections']['categories'] ?? array() ) as $row ) {
                $term_id = self::resolve_category( (array) ( $row['identity'] ?? array() ) );
                if ( is_wp_error( $term_id ) ) {
                    throw new RuntimeException( $term_id->get_error_message() );
                }
                if ( 'mirror' === $mode ) {
                    $wpdb->delete( $t['objects'], array( 'object_type'=>'product_cat', 'object_id'=>$term_id ) );
                }
                foreach ( (array) ( $row['semantic'] ?? array() ) as $semantic ) {
                    $vid = self::vocabulary_id( $semantic['semantic_group'] ?? '', $semantic['slug'] ?? '' );
                    if ( ! $vid ) {
                        continue;
                    }
                    $wpdb->query(
                        $wpdb->prepare(
                            "INSERT INTO %i (object_type,object_id,vocabulary_id,source,confidence,status)
                             VALUES ('product_cat',%d,%d,%s,%f,%d)
                             ON DUPLICATE KEY UPDATE source=VALUES(source),confidence=VALUES(confidence),status=VALUES(status),updated_at=CURRENT_TIMESTAMP", $t['objects'],
                            $term_id, $vid, sanitize_key( $semantic['source'] ?? 'portable' ),
                            max( 0, min( 1, (float) ( $semantic['confidence'] ?? 1 ) ) ),
                            empty( $semantic['status'] ) ? 0 : 1
                        )
                    );
                }
                $objects_count++;
            }

            $wpdb->query( 'COMMIT' );
        } catch ( Throwable $e ) {
            $wpdb->query( 'ROLLBACK' );
            return new WP_Error( 'tcv_portable_apply', $e->getMessage() );
        }

        return array( 'masters'=>$masters_count, 'objects'=>$objects_count );
    }

    // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    private static function redirect( $status, $message ) {
        wp_safe_redirect(
            add_query_arg(
                array(
                    'page'        => 'taxocommerce-vocabulary',
                    'tab'         => 'io',
                    'tcv_status'  => $status,
                    'tcv_message' => rawurlencode( $message ),
                ),
                admin_url( 'admin.php' )
            )
        );
        exit;
    }
}
