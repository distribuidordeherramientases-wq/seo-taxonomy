<?php
/**
 * TaxoCommerce SEO - Importacion explicita de categorias traducidas.
 *
 * Crea/actualiza product_cat sin tocar sus equivalentes espanoles y registra
 * category_translation en seo_relations. La identidad portable es el slug ES.
 * No usa IDs de otro entorno ni infiere equivalencias por parecido de nombres.
 */
defined( 'ABSPATH' ) || exit;

/** Solo mercados con una plantilla internacional operativa. */
function seo_ie_ct_markets() {
    return array( 'en-US' => 'us', 'de-DE' => 'de', 'nl-NL' => 'nl' );
}

/** Obtiene el locale guardado o el heredado de una raiz del piloto VEVOR. */
function seo_ie_ct_term_locale( $term ) {
    if ( ! $term instanceof WP_Term || 'product_cat' !== $term->taxonomy ) {
        return '';
    }
    $stored = (string) get_term_meta( $term->term_id, '_seo_locale', true );
    if ( '' !== $stored ) {
        return $stored;
    }
    $ids = array_merge( array( (int) $term->term_id ), get_ancestors( $term->term_id, 'product_cat', 'taxonomy' ) );
    foreach ( $ids as $id ) {
        $ancestor = get_term( $id, 'product_cat' );
        if ( ! $ancestor instanceof WP_Term ) {
            continue;
        }
        foreach ( seo_ie_ct_markets() as $locale => $market ) {
            if ( 0 === strpos( $ancestor->slug, 'vevor-' . $market . '-' ) || $ancestor->slug === 'vevor-' . $market ) {
                return $locale;
            }
        }
    }
    return '';
}

/** Verifica que no haya ya otra referencia ES ni otra version del mismo locale. */
function seo_ie_ct_check_relations( $source_id, $target_id, $locale ) {
    global $wpdb;
    $table = $wpdb->prefix . 'seo_relations';
    if ( $target_id ) {
        $sources = $wpdb->get_col( $wpdb->prepare(
            "SELECT DISTINCT source_id FROM {$table}
             WHERE relation_type = %s AND source_type = %s AND target_type = %s AND target_id = %d",
            'category_translation', 'product_cat', 'product_cat', $target_id
        ) );
        foreach ( (array) $sources as $current ) {
            if ( (int) $current !== $source_id ) {
                return new WP_Error( 'seo_ct_conflicting_origin', 'La categoria traducida ya apunta a otra categoria espanola.' );
            }
        }
    }

    $targets = $wpdb->get_col( $wpdb->prepare(
        "SELECT DISTINCT target_id FROM {$table}
         WHERE relation_type = %s AND source_type = %s AND target_type = %s AND source_id = %d",
        'category_translation', 'product_cat', 'product_cat', $source_id
    ) );
    foreach ( (array) $targets as $current_id ) {
        if ( (int) $current_id === $target_id ) {
            continue;
        }
        $current_term = get_term( (int) $current_id, 'product_cat' );
        if ( $current_term instanceof WP_Term && seo_ie_ct_term_locale( $current_term ) === $locale ) {
            return new WP_Error( 'seo_ct_duplicate_locale', 'La categoria espanola ya tiene otra traduccion para este locale.' );
        }
    }
    return true;
}

/** Un padre internacional nunca se resuelve mediante el ID ES del CSV. */
function seo_ie_ct_resolve_parent( $source, $locale, $explicit_slug ) {
    global $wpdb;
    $market = seo_ie_ct_markets()[ $locale ];
    if ( '' !== $explicit_slug ) {
        $parent = get_term_by( 'slug', sanitize_title( $explicit_slug ), 'product_cat' );
        if ( ! $parent || seo_ie_ct_term_locale( $parent ) !== $locale ) {
            return new WP_Error( 'seo_ct_parent_invalid', 'parent_slug no existe o no pertenece al mismo mercado.' );
        }
        return (int) $parent->term_id;
    }

    if ( (int) $source->parent > 0 ) {
        $table = $wpdb->prefix . 'seo_relations';
        $candidates = $wpdb->get_col( $wpdb->prepare(
            "SELECT target_id FROM {$table}
             WHERE source_type = %s AND source_id = %d AND target_type = %s AND relation_type = %s",
            'product_cat', (int) $source->parent, 'product_cat', 'category_translation'
        ) );
        foreach ( (array) $candidates as $candidate_id ) {
            $candidate = get_term( (int) $candidate_id, 'product_cat' );
            if ( $candidate instanceof WP_Term && seo_ie_ct_term_locale( $candidate ) === $locale ) {
                return (int) $candidate->term_id;
            }
        }
        return new WP_Error( 'seo_ct_parent_missing', 'Falta importar primero la traduccion de la categoria padre, o indicar parent_slug.' );
    }

    $root = get_term_by( 'slug', 'vevor-' . $market, 'product_cat' );
    if ( ! $root || seo_ie_ct_term_locale( $root ) !== $locale ) {
        return new WP_Error( 'seo_ct_market_root_missing', 'No existe la raiz del mercado vevor-' . $market . '. Indica parent_slug valido.' );
    }
    return (int) $root->term_id;
}

/** Inserta la relacion mediante Data Layer para conservar auditoria/rollback. */
function seo_ie_ct_link( $source_id, $target_id ) {
    global $wpdb;
    $table = $wpdb->prefix . 'seo_relations';
    $existing = (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT id FROM {$table}
         WHERE source_type = %s AND source_id = %d AND target_type = %s AND target_id = %d AND relation_type = %s LIMIT 1",
        'product_cat', $source_id, 'product_cat', $target_id, 'category_translation'
    ) );
    if ( $existing ) {
        return false; // Reimportacion: ya existe.
    }
    if ( ! class_exists( 'SEO_Data_Layer' ) || ! class_exists( 'SEO_Data_Operation' ) ) {
        return new WP_Error( 'seo_ct_data_layer_missing', 'El Data Layer no esta disponible.' );
    }
    try {
        $operation = SEO_Data_Layer::operation( array(
            'type' => 'category_translation',
            'label' => 'Vincular categoria ES con traduccion',
            'source_module' => 'import_export_category_translations',
            'risk_level' => 'low',
            'metadata' => array( 'source_term_id' => $source_id, 'target_term_id' => $target_id ),
        ) );
        $operation->mark_validated();
        $operation->execute( static function ( $op ) use ( $source_id, $target_id ) {
            $op->insert( 'relations', array(
                'source_type' => 'product_cat',
                'source_id' => $source_id,
                'target_type' => 'product_cat',
                'target_id' => $target_id,
                'relation_type' => 'category_translation',
                'created_at' => current_time( 'mysql' ),
            ) );
        } );
    } catch ( Throwable $e ) {
        return new WP_Error( 'seo_ct_relation_failed', $e->getMessage() );
    }
    return true;
}

/** Una fila de CSV: verifica ES -> crea/actualiza WP -> guarda contenido -> vincula. */
function seo_ie_ct_import_row( array $row ) {
    $locale = trim( (string) ( $row['locale'] ?? '' ) );
    $source_slug = sanitize_title( (string) ( $row['source_slug'] ?? '' ) );
    $title = sanitize_text_field( html_entity_decode( (string) ( $row['titulo'] ?? '' ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
    $slug = sanitize_title( (string) ( $row['slug'] ?? '' ) );
    $parent_slug = sanitize_title( (string) ( $row['parent_slug'] ?? '' ) );

    if ( ! isset( seo_ie_ct_markets()[ $locale ] ) || '' === $source_slug || '' === $title || '' === $slug ) {
        return new WP_Error( 'seo_ct_incomplete', 'Se necesitan source_slug ES, locale en-US/de-DE/nl-NL, titulo y slug traducidos.' );
    }
    $source = get_term_by( 'slug', $source_slug, 'product_cat' );
    if ( ! $source instanceof WP_Term || ! in_array( seo_ie_ct_term_locale( $source ), array( '', 'es-ES' ), true ) ) {
        return new WP_Error( 'seo_ct_source_invalid', 'source_slug debe identificar una categoria espanola existente.' );
    }
    $source_id = (int) $source->term_id;
    $target = get_term_by( 'slug', $slug, 'product_cat' );
    $target_id = $target instanceof WP_Term ? (int) $target->term_id : 0;
    if ( $target_id === $source_id ) {
        return new WP_Error( 'seo_ct_same_term', 'La categoria traducida no puede ser la categoria espanola.' );
    }
    if ( $target_id && seo_ie_ct_term_locale( $target ) !== $locale ) {
        return new WP_Error( 'seo_ct_slug_collision', 'El slug pertenece a una categoria de otro mercado o sin identificacion de idioma.' );
    }
    $check = seo_ie_ct_check_relations( $source_id, $target_id, $locale );
    if ( is_wp_error( $check ) ) {
        return $check;
    }
    if ( ! class_exists( 'SEO_Data_Layer' ) || ! class_exists( 'SEO_Data_Operation' ) ) {
        return new WP_Error( 'seo_ct_missing_service', 'El servicio auditable SEO Data Layer no esta disponible.' );
    }

    $description = array_key_exists( 'description', $row ) ? wp_kses_post( (string) $row['description'] ) : '';
    $excerpt = array_key_exists( 'excerpt', $row ) ? wp_kses_post( (string) $row['excerpt'] ) : '';
    $is_new = ! $target_id;
    if ( $is_new ) {
        $parent = seo_ie_ct_resolve_parent( $source, $locale, $parent_slug );
        if ( is_wp_error( $parent ) ) {
            return $parent;
        }
        $args = array( 'slug' => $slug, 'parent' => $parent );
        if ( '' !== trim( $description ) ) {
            $args['description'] = $description;
        }
        $result = wp_insert_term( $title, 'product_cat', $args );
        if ( is_wp_error( $result ) ) {
            return $result;
        }
        $target_id = (int) $result['term_id'];
    } else {
        $args = array( 'name' => $title );
        if ( '' !== trim( $description ) ) {
            $args['description'] = $description;
        }
        // Las categorias internacionales del piloto mantienen su jerarquia.
        $result = wp_update_term( $target_id, 'product_cat', $args );
        if ( is_wp_error( $result ) ) {
            return $result;
        }
    }

    if ( false === update_term_meta( $target_id, '_seo_locale', $locale ) && (string) get_term_meta( $target_id, '_seo_locale', true ) !== $locale ) {
        return new WP_Error( 'seo_ct_locale_save_failed', 'No se pudo guardar locale; revisa la categoria antes de reimportar.' );
    }
    if ( '' === (string) get_term_meta( $source_id, '_seo_locale', true ) ) {
        update_term_meta( $source_id, '_seo_locale', 'es-ES' );
    }
    if ( '' !== trim( wp_strip_all_tags( $excerpt ) ) ) {
        update_term_meta( $target_id, 'seo_excerpt', $excerpt );
        if ( ! seo_ie_upsert_node_value( 'category', $target_id, 'excerpt', $excerpt ) ) {
            return new WP_Error( 'seo_ct_excerpt_failed', 'Categoria creada pero fallo al guardar excerpt; reimporta la fila.' );
        }
    }
    if ( '' !== trim( wp_strip_all_tags( $description ) ) ) {
        if ( ! seo_ie_upsert_node_value( 'category', $target_id, 'description', $description ) ) {
            return new WP_Error( 'seo_ct_description_failed', 'Categoria creada pero fallo al guardar description; reimporta la fila.' );
        }
    }

    $linked = seo_ie_ct_link( $source_id, $target_id );
    if ( is_wp_error( $linked ) ) {
        return $linked;
    }
    return array( 'term_id' => $target_id, 'created' => $is_new, 'linked' => (bool) $linked );
}

/** Importacion manual limitada a 100 filas; usa el CSV ; del importador existente. */
function seo_ie_category_translations_import() {
    if ( ! isset( $_POST['seo_ie_import_category_translations'] ) ) {
        return;
    }
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( esc_html__( 'Permisos insuficientes.', 'taxocommerce-seo' ) );
    }
    check_admin_referer( 'seo_ie_import_category_translations', 'seo_ie_category_translations_nonce' );
    // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Se valida extension, tamano y fichero temporal antes de leer.
    $file = isset( $_FILES['seo_ie_category_translations_csv'] ) && is_array( $_FILES['seo_ie_category_translations_csv'] )
        ? $_FILES['seo_ie_category_translations_csv'] : array();
    $filename = sanitize_file_name( (string) ( $file['name'] ?? '' ) );
    $path = (string) ( $file['tmp_name'] ?? '' );
    if ( (int) ( $file['error'] ?? UPLOAD_ERR_NO_FILE ) !== UPLOAD_ERR_OK
        || ! preg_match( '/\.csv$/i', $filename )
        || (int) ( $file['size'] ?? 0 ) < 1
        || (int) ( $file['size'] ?? 0 ) > 8 * MB_IN_BYTES
        || ! is_uploaded_file( $path ) ) {
        wp_die( esc_html__( 'Selecciona un CSV valido de hasta 8 MB.', 'taxocommerce-seo' ) );
    }
    $handle = seo_ie_stream_open( $path, 'r' );
    if ( ! is_resource( $handle ) ) {
        wp_die( esc_html__( 'No se puede leer el CSV.', 'taxocommerce-seo' ) );
    }
    $head = seo_ie_read_csv_row( $handle );
    if ( ! is_array( $head ) ) {
        seo_ie_stream_close( $handle );
        wp_die( esc_html__( 'El CSV esta vacio.', 'taxocommerce-seo' ) );
    }
    $aliases = array(
        'categoria_origen_slug' => 'source_slug',
        'original_slug' => 'source_slug',
        'source_category_slug' => 'source_slug',
        'name' => 'titulo',
        'title' => 'titulo',
        'target_slug' => 'slug',
        'idioma' => 'locale',
        'idioma_pais' => 'locale',
        'descripcion' => 'description',
        'resumen' => 'excerpt',
        'translated_parent_slug' => 'parent_slug',
    );
    $header = array();
    foreach ( $head as $cell ) {
        $key = sanitize_key( trim( preg_replace( '/^\xEF\xBB\xBF/', '', (string) $cell ) ) );
        $header[] = $aliases[ $key ] ?? $key;
    }
    foreach ( array( 'source_slug', 'locale', 'titulo', 'slug' ) as $required ) {
        if ( ! in_array( $required, $header, true ) ) {
            seo_ie_stream_close( $handle );
            wp_die( esc_html( 'Falta la columna ' . $required . ' en el CSV.' ) );
        }
    }
    $rows = array();
    while ( false !== ( $values = seo_ie_read_csv_row( $handle ) ) ) {
        if ( count( $values ) === 1 && '' === trim( (string) $values[0] ) ) {
            continue;
        }
        $rows[] = seo_ie_build_csv_row( $header, $values );
        if ( count( $rows ) > 100 ) {
            seo_ie_stream_close( $handle );
            wp_die( esc_html__( 'Maximo 100 categorias por archivo. Divide el CSV en lotes.', 'taxocommerce-seo' ) );
        }
    }
    seo_ie_stream_close( $handle );
    $log = array( 'created' => 0, 'updated' => 0, 'linked' => 0, 'errors' => 0, 'details' => array() );
    foreach ( $rows as $index => $row ) {
        $result = seo_ie_ct_import_row( $row );
        if ( is_wp_error( $result ) ) {
            ++$log['errors'];
            if ( count( $log['details'] ) < 20 ) {
                $log['details'][] = sprintf( 'Fila %d: %s', $index + 2, $result->get_error_message() );
            }
            continue;
        }
        ++$log[ $result['created'] ? 'created' : 'updated' ];
        if ( $result['linked'] ) {
            ++$log['linked'];
        }
    }
    set_transient( 'seo_ie_ct_report_' . get_current_user_id(), $log, 15 * MINUTE_IN_SECONDS );
    wp_safe_redirect( add_query_arg(
        array( 'page' => 'seo-import-export', 'seo_ie_tab' => 'wordpress' ),
        admin_url( 'admin.php' )
    ) );
    exit;
}
add_action( 'admin_init', 'seo_ie_category_translations_import' );

/** El limpiador debe reconocer las relaciones legitimas del nuevo modulo. */
add_filter( 'seo_clean_db_relation_schemas', static function ( $schemas ) {
    $schemas['category_translation'] = array(
        'source_type' => 'product_cat',
        'target_type' => 'product_cat',
        'scope' => 'translation',
    );
    return $schemas;
} );

/** Formulario al lado de la importacion general de categorias. */
function seo_ie_category_translations_form() {
    $report_key = 'seo_ie_ct_report_' . get_current_user_id();
    $report = get_transient( $report_key );
    if ( is_array( $report ) ) {
        delete_transient( $report_key );
        printf(
            '<div class="notice notice-info inline"><p>Traducciones: %d creadas, %d actualizadas, %d nuevas relaciones, %d errores.</p></div>',
            (int) $report['created'], (int) $report['updated'], (int) $report['linked'], (int) $report['errors']
        );
        foreach ( (array) $report['details'] as $detail ) {
            echo '<p>' . esc_html( $detail ) . '</p>';
        }
    }
    echo '<div class="card" style="max-width:none;padding:20px;">';
    echo '<h2>Importar traducciones de categorias</h2>';
    echo '<p>Crea o actualiza cada categoria en su idioma y la vincula automaticamente con la categoria espanola en seo_relations. No traduce textos ni modifica la categoria original.</p>';
    echo '<p><strong>CSV UTF-8 separado por punto y coma:</strong> <code>source_slug;locale;titulo;slug;excerpt;description;parent_slug</code></p>';
    echo '<p><code>source_slug</code> es el slug de la categoria espanola (obligatorio). <code>parent_slug</code> es opcional y corresponde al padre traducido, nunca al ID espanol. La importacion usa el slug para funcionar en STAGING y PRO sin mezclar IDs. Maximo 100 filas por archivo.</p>';
    echo '<form method="post" enctype="multipart/form-data">';
    wp_nonce_field( 'seo_ie_import_category_translations', 'seo_ie_category_translations_nonce' );
    echo '<input type="file" name="seo_ie_category_translations_csv" accept=".csv,text/csv" required>';
    echo '<p><button type="submit" name="seo_ie_import_category_translations" value="1" class="button button-secondary">Importar traducciones de categorias</button></p>';
    echo '</form></div>';
}
add_action( 'seo_ie_category_translations_form', 'seo_ie_category_translations_form' );
