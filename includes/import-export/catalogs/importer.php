<?php
/**
 * SEO System — Importador seguro de catálogos canónicos.
 *
 * Permite dar de alta, mediante un único CSV plano, el vocabulario que debe
 * existir ANTES de importar productos enriquecidos. No modifica ni elimina
 * registros existentes: solo crea valores faltantes y rechaza conflictos.
 *
 * Tipos de fila admitidos:
 * - vocabulary: ROL, TIPO, APLICACION, PLATAFORMA y SUBTIPO.
 * - type_role_map: relación obligatoria TIPO -> ROL.
 * - attribute: definición canónica de atributo.
 * - attribute_term: valor controlado de un atributo tipo termino.
 * - attribute_alias: alias de un término controlado.
 * - product_tag: etiqueta WooCommerce product_tag.
 *
 * El formato es compatible con el CSV "catálogos obligatorios" exportado por
 * SEO System. Para altas nuevas los IDs pueden quedar vacíos; las dependencias
 * se resuelven por slug y el orden de las filas no importa.
 *
 * @package SEOSystem
 * @subpackage ImportExport
 * @since 2.3.4
 */

defined( 'ABSPATH' ) || exit;

add_action( 'admin_init', 'seo_ie_import_required_catalogs_csv' );

/**
 * Grupos semánticos que pueden crearse desde el importador masivo.
 * Los grupos internos del sistema se pueden exportar, pero no crear aquí.
 *
 * @return string[]
 */
function seo_ie_catalog_import_allowed_semantic_groups() {
    return [ 'rol', 'tipo', 'aplicacion', 'plataforma', 'subtipo' ];
}

/**
 * Convierte un booleano CSV tolerando valores habituales.
 *
 * @param mixed $value Valor CSV.
 * @param bool  $default Valor si la celda está vacía/no reconocida.
 * @return bool
 */
function seo_ie_catalog_import_bool( $value, $default = false ) {
    $value = trim( remove_accents( mb_strtolower( (string) $value, 'UTF-8' ) ) );
    if ( '' === $value ) {
        return (bool) $default;
    }
    if ( in_array( $value, [ '1', 'true', 'yes', 'si', 'on', 'activo', 'active' ], true ) ) {
        return true;
    }
    if ( in_array( $value, [ '0', 'false', 'no', 'off', 'inactivo', 'inactive' ], true ) ) {
        return false;
    }
    return (bool) $default;
}

/**
 * Slug semántico. Respeta slugs proporcionados y genera uno con guiones bajos
 * cuando la celda viene vacía para mantener el estilo del vocabulario actual.
 *
 * @param mixed  $raw Valor de slug.
 * @param string $fallback Nombre/label para autogenerar.
 * @return string
 */
function seo_ie_catalog_import_semantic_slug( $raw, $fallback = '' ) {
    $raw = trim( (string) $raw );
    if ( '' !== $raw ) {
        return sanitize_key( remove_accents( mb_strtolower( $raw, 'UTF-8' ) ) );
    }
    $generated = sanitize_title( remove_accents( (string) $fallback ) );
    return sanitize_key( str_replace( '-', '_', $generated ) );
}

/**
 * Slug de atributo canónico (usa guión bajo).
 *
 * @param mixed  $raw Valor CSV.
 * @param string $fallback Nombre visible.
 * @return string
 */
function seo_ie_catalog_import_attribute_slug( $raw, $fallback = '' ) {
    $raw = trim( (string) $raw );
    $base = '' !== $raw ? $raw : (string) $fallback;
    return sanitize_key( str_replace( '-', '_', sanitize_title( remove_accents( $base ) ) ) );
}

/**
 * Tipo de fila, con compatibilidad por nombre lógico de tabla.
 *
 * @param array $row Fila CSV.
 * @return string
 */
function seo_ie_catalog_import_row_type( array $row ) {
    $type = sanitize_key( (string) ( $row['tipo_registro'] ?? '' ) );
    if ( '' !== $type ) {
        return $type;
    }

    $table = sanitize_key( (string) ( $row['tabla'] ?? '' ) );
    $map = [
        'seo_vocabulary'         => 'vocabulary',
        'seo_type_role_map'      => 'type_role_map',
        'sql_atributos'          => 'attribute',
        'sql_atributos_terminos' => 'attribute_term',
        'sql_atributos_aliases'  => 'attribute_alias',
        'woocommerce_product_tag'=> 'product_tag',
        'product_tag'            => 'product_tag',
    ];
    return $map[ $table ] ?? '';
}

/**
 * Busca una entrada de vocabulario por grupo + slug.
 *
 * @param string $group Grupo.
 * @param string $slug Slug.
 * @return array|null
 */
function seo_ie_catalog_import_get_vocabulary( $group, $slug ) {
    global $wpdb;
    $table = $wpdb->prefix . 'seo_vocabulary';
    $row = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT id, semantic_group, slug, label, active FROM {$table} WHERE semantic_group=%s AND slug=%s LIMIT 1",
            sanitize_key( $group ),
            sanitize_key( $slug )
        ),
        ARRAY_A
    );
    return is_array( $row ) ? $row : null;
}

/**
 * Busca una definición de atributo por slug.
 *
 * @param string $slug Slug.
 * @return array|null
 */
function seo_ie_catalog_import_get_attribute( $slug ) {
    global $wpdb;
    $table = $wpdb->prefix . 'sql_atributos';
    $row = $wpdb->get_row(
        $wpdb->prepare( "SELECT * FROM {$table} WHERE slug=%s LIMIT 1", sanitize_key( $slug ) ),
        ARRAY_A
    );
    return is_array( $row ) ? $row : null;
}

/**
 * Busca un término controlado por atributo + slug.
 *
 * @param int    $attribute_id ID atributo.
 * @param string $term_slug Slug término.
 * @return array|null
 */
function seo_ie_catalog_import_get_attribute_term( $attribute_id, $term_slug ) {
    global $wpdb;
    $table = $wpdb->prefix . 'sql_atributos_terminos';
    $row = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT * FROM {$table} WHERE atributo_id=%d AND slug=%s LIMIT 1",
            absint( $attribute_id ),
            sanitize_title( $term_slug )
        ),
        ARRAY_A
    );
    return is_array( $row ) ? $row : null;
}

/**
 * Devuelve el mapeo TIPO -> ROL actual.
 *
 * @param int $type_id ID TIPO.
 * @return array|null
 */
function seo_ie_catalog_import_get_type_role_map( $type_id ) {
    global $wpdb;
    $table = $wpdb->prefix . 'seo_type_role_map';
    $row = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT id, type_vocabulary_id, role_vocabulary_id, active FROM {$table} WHERE type_vocabulary_id=%d LIMIT 1",
            absint( $type_id )
        ),
        ARRAY_A
    );
    return is_array( $row ) ? $row : null;
}

/**
 * Añade un detalle con línea CSV.
 *
 * @param array  $log Log.
 * @param int    $line Línea.
 * @param string $message Mensaje.
 * @return void
 */
function seo_ie_catalog_import_log( &$log, $line, $message ) {
    seo_ie_add_log_detail( $log, sprintf( 'Fila %d: %s', (int) $line, $message ) );
}

/**
 * Registra una fila válida ya existente.
 */
function seo_ie_catalog_import_skip( &$log, $line, $message ) {
    $log['correctos']++;
    $log['omitidos']++;
    seo_ie_catalog_import_log( $log, $line, $message );
}

/**
 * Registra error de fila.
 */
function seo_ie_catalog_import_error( &$log, $line, $message ) {
    $log['errores']++;
    seo_ie_catalog_import_log( $log, $line, 'ERROR: ' . $message );
}

/**
 * Registra una alta real/simulada.
 */
function seo_ie_catalog_import_created( &$log, $line, $kind, $message, $dry_run ) {
    $log['creados']++;
    if ( ! isset( $log['altas_por_tipo'][ $kind ] ) ) {
        $log['altas_por_tipo'][ $kind ] = 0;
    }
    $log['altas_por_tipo'][ $kind ]++;
    seo_ie_catalog_import_log( $log, $line, ( $dry_run ? 'SIMULAR: ' : 'CREADO: ' ) . $message );
}

/**
 * Inserta vocabulario no-TIPO (ROL/APLICACIÓN/PLATAFORMA/SUBTIPO).
 *
 * @return bool true cuando la fila quedó resuelta (alta o existente).
 */
function seo_ie_catalog_import_vocabulary_row( array $item, &$log, $dry_run ) {
    global $wpdb;
    $row  = $item['row'];
    $line = $item['line'];

    $group = sanitize_key( (string) ( $row['semantic_group'] ?? '' ) );
    $label = sanitize_text_field( trim( (string) ( $row['label'] ?? '' ) ) );
    $slug  = seo_ie_catalog_import_semantic_slug( $row['slug'] ?? '', $label );

    if ( '' === $group || '' === $label || '' === $slug ) {
        seo_ie_catalog_import_error( $log, $line, 'semantic_group, label y slug son obligatorios para vocabulary.' );
        return false;
    }

    $existing = seo_ie_catalog_import_get_vocabulary( $group, $slug );

    if ( ! in_array( $group, seo_ie_catalog_import_allowed_semantic_groups(), true ) ) {
        if ( $existing ) {
            seo_ie_catalog_import_skip( $log, $line, "Grupo interno {$group}/{$slug}: ya existe y no se modifica." );
            return true;
        }
        seo_ie_catalog_import_error( $log, $line, "El grupo semántico «{$group}» es interno/no importable. Solo se admiten rol, tipo, aplicacion, plataforma y subtipo." );
        return false;
    }

    if ( $existing ) {
        if ( empty( $existing['active'] ) ) {
            seo_ie_catalog_import_error( $log, $line, "{$group}/{$slug} ya existe pero está inactivo. Reactívalo manualmente; este importador no modifica existentes." );
            return false;
        }
        seo_ie_catalog_import_skip( $log, $line, "{$group}/{$slug} ya existe." );
        return true;
    }

    if ( array_key_exists( 'active', $row ) && '' !== trim( (string) $row['active'] ) && ! seo_ie_catalog_import_bool( $row['active'], true ) ) {
        seo_ie_catalog_import_error( $log, $line, 'El importador de altas no crea entradas inactivas.' );
        return false;
    }

    if ( $dry_run ) {
        seo_ie_catalog_import_created( $log, $line, 'vocabulary', "{$group}/{$slug} — {$label}", true );
        $log['correctos']++;
        return true;
    }

    $table = $wpdb->prefix . 'seo_vocabulary';
    $ok = $wpdb->insert(
        $table,
        [
            'semantic_group' => $group,
            'slug'           => $slug,
            'label'          => $label,
            'parent_id'      => null,
            'source'         => 'catalog_csv_import',
            'active'         => 1,
            'created_at'     => current_time( 'mysql' ),
            'updated_at'     => current_time( 'mysql' ),
        ],
        [ '%s', '%s', '%s', '%d', '%s', '%d', '%s', '%s' ]
    );

    if ( false === $ok ) {
        seo_ie_catalog_import_error( $log, $line, 'No se pudo crear vocabulary: ' . ( $wpdb->last_error ?: 'error SQL desconocido' ) );
        return false;
    }

    seo_ie_catalog_import_created( $log, $line, 'vocabulary', "{$group}/{$slug} — {$label}", false );
    $log['correctos']++;
    return true;
}

/**
 * Devuelve el role_slug solicitado por un TIPO nuevo.
 * Acepta role_slug en la propia fila vocabulary o una fila type_role_map.
 *
 * @param array $type_item Fila TIPO.
 * @param array $maps_by_type Mapeos indexados.
 * @return string|WP_Error
 */
function seo_ie_catalog_import_role_for_type( array $type_item, array $maps_by_type ) {
    $row = $type_item['row'];
    $type_slug = seo_ie_catalog_import_semantic_slug( $row['slug'] ?? '', $row['label'] ?? '' );
    $direct = seo_ie_catalog_import_semantic_slug( $row['role_slug'] ?? '', $row['role_label'] ?? '' );
    if ( '' !== $direct ) {
        return $direct;
    }

    if ( empty( $maps_by_type[ $type_slug ] ) ) {
        return new WP_Error( 'missing_type_role', 'Todo TIPO nuevo debe indicar role_slug o incluir una fila type_role_map para ese type_slug.' );
    }

    $roles = [];
    foreach ( $maps_by_type[ $type_slug ] as $map_item ) {
        $map_row = $map_item['row'];
        $role_slug = seo_ie_catalog_import_semantic_slug( $map_row['role_slug'] ?? '', $map_row['role_label'] ?? '' );
        if ( '' !== $role_slug ) {
            $roles[ $role_slug ] = true;
        }
    }

    if ( 1 !== count( $roles ) ) {
        return new WP_Error( 'ambiguous_type_role', 'El TIPO tiene cero o varios ROL distintos en el CSV.' );
    }

    return (string) array_key_first( $roles );
}

/**
 * Crea un TIPO y su ROL de forma atómica, o valida un TIPO existente.
 *
 * @return bool
 */
function seo_ie_catalog_import_type_row( array $item, array $maps_by_type, &$log, $dry_run, &$handled_maps ) {
    global $wpdb;
    $row  = $item['row'];
    $line = $item['line'];

    $label = sanitize_text_field( trim( (string) ( $row['label'] ?? '' ) ) );
    $slug  = seo_ie_catalog_import_semantic_slug( $row['slug'] ?? '', $label );
    if ( '' === $label || '' === $slug ) {
        seo_ie_catalog_import_error( $log, $line, 'TIPO: label y slug son obligatorios.' );
        return false;
    }

    $existing = seo_ie_catalog_import_get_vocabulary( 'tipo', $slug );
    if ( $existing && empty( $existing['active'] ) ) {
        seo_ie_catalog_import_error( $log, $line, "TIPO {$slug} ya existe pero está inactivo." );
        return false;
    }

    $direct_role_slug = seo_ie_catalog_import_semantic_slug( $row['role_slug'] ?? '', $row['role_label'] ?? '' );
    $has_role_hint = '' !== $direct_role_slug || ! empty( $maps_by_type[ $slug ] );

    // Un export completo puede contener TIPOs históricos sin mapa activo. Como
    // este importador es add-only, si el TIPO ya existe y el CSV no propone
    // explícitamente un ROL no convierte esa deuda previa en un error de alta.
    if ( $existing && ! $has_role_hint ) {
        seo_ie_catalog_import_skip( $log, $line, "TIPO {$slug} ya existe; el CSV no propone un mapeo ROL nuevo y no se modifica." );
        return true;
    }

    $role_slug = seo_ie_catalog_import_role_for_type( $item, $maps_by_type );
    if ( is_wp_error( $role_slug ) ) {
        seo_ie_catalog_import_error( $log, $line, 'TIPO ' . $slug . ': ' . $role_slug->get_error_message() );
        return false;
    }

    $role = seo_ie_catalog_import_get_vocabulary( 'rol', $role_slug );
    if ( ! $role || empty( $role['active'] ) ) {
        // En simulación puede tratarse de un ROL que también se creará en este CSV.
        if ( $dry_run && ! empty( $log['_prospective_roles'][ $role_slug ] ) ) {
            $role = [ 'id' => 0, 'slug' => $role_slug, 'active' => 1 ];
        } else {
            seo_ie_catalog_import_error( $log, $line, "TIPO {$slug}: el ROL «{$role_slug}» no existe o no está activo." );
            return false;
        }
    }

    if ( $existing ) {
        $map = seo_ie_catalog_import_get_type_role_map( (int) $existing['id'] );
        if ( $map && ! empty( $map['active'] ) ) {
            $current_role = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT slug FROM {$wpdb->prefix}seo_vocabulary WHERE id=%d AND semantic_group='rol' LIMIT 1",
                    (int) $map['role_vocabulary_id']
                )
            );
            if ( sanitize_key( (string) $current_role ) !== $role_slug ) {
                seo_ie_catalog_import_error( $log, $line, "TIPO {$slug} ya está asociado al ROL «{$current_role}», no a «{$role_slug}». El importador no cambia mapeos existentes." );
                return false;
            }
            seo_ie_catalog_import_skip( $log, $line, "TIPO {$slug} y su ROL {$role_slug} ya existen." );
            $handled_maps[ $slug ] = true;
            return true;
        }

        // El TIPO existe pero carece de mapa activo: se puede crear la pieza
        // faltante únicamente cuando el CSV la ha pedido explícitamente.
        if ( $dry_run ) {
            seo_ie_catalog_import_created( $log, $line, 'type_role_map', "Mapear TIPO {$slug} -> ROL {$role_slug}", true );
            $log['correctos']++;
            $handled_maps[ $slug ] = true;
            return true;
        }

        if ( $map ) {
            seo_ie_catalog_import_error( $log, $line, "TIPO {$slug} tiene un mapeo inactivo. Revísalo manualmente antes de importar." );
            return false;
        }

        $ok = $wpdb->insert(
            $wpdb->prefix . 'seo_type_role_map',
            [
                'type_vocabulary_id' => (int) $existing['id'],
                'role_vocabulary_id' => (int) $role['id'],
                'confidence'         => 1.0000,
                'source'             => 'catalog_csv_import',
                'active'             => 1,
            ],
            [ '%d', '%d', '%f', '%s', '%d' ]
        );
        if ( false === $ok ) {
            seo_ie_catalog_import_error( $log, $line, 'No se pudo crear el mapeo TIPO -> ROL: ' . ( $wpdb->last_error ?: 'error SQL desconocido' ) );
            return false;
        }
        seo_ie_catalog_import_created( $log, $line, 'type_role_map', "Mapeo TIPO {$slug} -> ROL {$role_slug}", false );
        $log['correctos']++;
        $handled_maps[ $slug ] = true;
        return true;
    }

    // Los TIPOs realmente nuevos sí requieren ROL antes de existir.
    if ( $dry_run ) {
        seo_ie_catalog_import_created( $log, $line, 'vocabulary', "TIPO {$slug} — {$label}", true );
        seo_ie_catalog_import_created( $log, $line, 'type_role_map', "Mapeo TIPO {$slug} -> ROL {$role_slug}", true );
        $log['correctos']++;
        $handled_maps[ $slug ] = true;
        return true;
    }

    $vocabulary = $wpdb->prefix . 'seo_vocabulary';
    $map_table  = $wpdb->prefix . 'seo_type_role_map';
    $wpdb->query( 'START TRANSACTION' );

    $ok = $wpdb->insert(
        $vocabulary,
        [
            'semantic_group' => 'tipo',
            'slug'           => $slug,
            'label'          => $label,
            'parent_id'      => null,
            'source'         => 'catalog_csv_import',
            'active'         => 1,
            'created_at'     => current_time( 'mysql' ),
            'updated_at'     => current_time( 'mysql' ),
        ],
        [ '%s', '%s', '%s', '%d', '%s', '%d', '%s', '%s' ]
    );

    if ( false === $ok ) {
        $error = $wpdb->last_error;
        $wpdb->query( 'ROLLBACK' );
        seo_ie_catalog_import_error( $log, $line, 'No se pudo crear TIPO: ' . ( $error ?: 'error SQL desconocido' ) );
        return false;
    }

    $type_id = (int) $wpdb->insert_id;
    $ok = $wpdb->insert(
        $map_table,
        [
            'type_vocabulary_id' => $type_id,
            'role_vocabulary_id' => (int) $role['id'],
            'confidence'         => 1.0000,
            'source'             => 'catalog_csv_import',
            'active'             => 1,
        ],
        [ '%d', '%d', '%f', '%s', '%d' ]
    );

    if ( false === $ok ) {
        $error = $wpdb->last_error;
        $wpdb->query( 'ROLLBACK' );
        seo_ie_catalog_import_error( $log, $line, 'Se canceló el alta del TIPO porque falló TIPO -> ROL: ' . ( $error ?: 'error SQL desconocido' ) );
        return false;
    }

    $wpdb->query( 'COMMIT' );
    seo_ie_catalog_import_created( $log, $line, 'vocabulary', "TIPO {$slug} — {$label}", false );
    seo_ie_catalog_import_created( $log, $line, 'type_role_map', "Mapeo TIPO {$slug} -> ROL {$role_slug}", false );
    $log['correctos']++;
    $handled_maps[ $slug ] = true;
    return true;
}

/**
 * Procesa una fila type_role_map que no haya quedado resuelta junto a un TIPO.
 */
function seo_ie_catalog_import_map_row( array $item, &$log, $dry_run ) {
    global $wpdb;
    $row  = $item['row'];
    $line = $item['line'];
    $type_slug = seo_ie_catalog_import_semantic_slug( $row['type_slug'] ?? '', $row['type_label'] ?? '' );
    $role_slug = seo_ie_catalog_import_semantic_slug( $row['role_slug'] ?? '', $row['role_label'] ?? '' );

    if ( '' === $type_slug || '' === $role_slug ) {
        seo_ie_catalog_import_error( $log, $line, 'type_role_map requiere type_slug y role_slug.' );
        return false;
    }

    $type = seo_ie_catalog_import_get_vocabulary( 'tipo', $type_slug );
    $role = seo_ie_catalog_import_get_vocabulary( 'rol', $role_slug );
    if ( ! $type || empty( $type['active'] ) ) {
        seo_ie_catalog_import_error( $log, $line, "El TIPO «{$type_slug}» no existe o no está activo." );
        return false;
    }
    if ( ! $role || empty( $role['active'] ) ) {
        seo_ie_catalog_import_error( $log, $line, "El ROL «{$role_slug}» no existe o no está activo." );
        return false;
    }

    $existing = seo_ie_catalog_import_get_type_role_map( (int) $type['id'] );
    if ( $existing ) {
        if ( (int) $existing['role_vocabulary_id'] !== (int) $role['id'] ) {
            seo_ie_catalog_import_error( $log, $line, "El TIPO «{$type_slug}» ya tiene otro ROL. No se modifica automáticamente." );
            return false;
        }
        if ( empty( $existing['active'] ) ) {
            seo_ie_catalog_import_error( $log, $line, "El mapeo {$type_slug} -> {$role_slug} existe inactivo. Reactívalo manualmente." );
            return false;
        }
        seo_ie_catalog_import_skip( $log, $line, "Mapeo {$type_slug} -> {$role_slug} ya existe." );
        return true;
    }

    if ( $dry_run ) {
        seo_ie_catalog_import_created( $log, $line, 'type_role_map', "Mapeo {$type_slug} -> {$role_slug}", true );
        $log['correctos']++;
        return true;
    }

    $confidence = is_numeric( $row['confidence'] ?? null ) ? (float) $row['confidence'] : 1.0;
    $confidence = max( 0.0, min( 1.0, $confidence ) );
    $ok = $wpdb->insert(
        $wpdb->prefix . 'seo_type_role_map',
        [
            'type_vocabulary_id' => (int) $type['id'],
            'role_vocabulary_id' => (int) $role['id'],
            'confidence'         => $confidence,
            'source'             => 'catalog_csv_import',
            'active'             => 1,
        ],
        [ '%d', '%d', '%f', '%s', '%d' ]
    );
    if ( false === $ok ) {
        seo_ie_catalog_import_error( $log, $line, 'No se pudo crear type_role_map: ' . ( $wpdb->last_error ?: 'error SQL desconocido' ) );
        return false;
    }
    seo_ie_catalog_import_created( $log, $line, 'type_role_map', "Mapeo {$type_slug} -> {$role_slug}", false );
    $log['correctos']++;
    return true;
}

/** Procesa definición de atributo. */
function seo_ie_catalog_import_attribute_row( array $item, &$log, $dry_run ) {
    $row  = $item['row'];
    $line = $item['line'];
    $name = sanitize_text_field( trim( (string) ( $row['attribute_name'] ?? $row['label'] ?? '' ) ) );
    $slug = seo_ie_catalog_import_attribute_slug( $row['attribute_slug'] ?? $row['slug'] ?? '', $name );
    $type = sanitize_key( (string) ( $row['attribute_type'] ?? 'texto' ) );

    if ( '' === $name || '' === $slug ) {
        seo_ie_catalog_import_error( $log, $line, 'attribute requiere attribute_name y attribute_slug.' );
        return false;
    }
    if ( ! in_array( $type, [ 'texto', 'numero', 'boolean', 'termino', 'rango' ], true ) ) {
        seo_ie_catalog_import_error( $log, $line, "Tipo de atributo «{$type}» no válido." );
        return false;
    }

    $existing = seo_ie_catalog_import_get_attribute( $slug );
    if ( $existing ) {
        if ( empty( $existing['activo'] ) ) {
            seo_ie_catalog_import_error( $log, $line, "El atributo «{$slug}» ya existe pero está inactivo." );
            return false;
        }
        if ( (string) $existing['tipo'] !== $type ) {
            seo_ie_catalog_import_error( $log, $line, "El atributo «{$slug}» ya existe como tipo «{$existing['tipo']}», no «{$type}»." );
            return false;
        }
        seo_ie_catalog_import_skip( $log, $line, "Atributo {$slug} ya existe." );
        return true;
    }

    if ( ! function_exists( 'seo_attributes_save_definition' ) ) {
        seo_ie_catalog_import_error( $log, $line, 'No está cargado el servicio canónico de atributos.' );
        return false;
    }

    if ( $dry_run ) {
        seo_ie_catalog_import_created( $log, $line, 'attribute', "Atributo {$slug} ({$type})", true );
        $log['correctos']++;
        return true;
    }

    try {
        seo_attributes_save_definition(
            [
                'id'          => 0,
                'slug'        => $slug,
                'nombre'      => $name,
                'grupo'       => sanitize_text_field( trim( (string) ( $row['attribute_group'] ?? 'general' ) ) ) ?: 'general',
                'tipo'        => $type,
                'unidad_tipo' => sanitize_text_field( trim( (string) ( $row['unit_type'] ?? '' ) ) ),
                'unidad_base' => sanitize_text_field( trim( (string) ( $row['base_unit'] ?? '' ) ) ),
                'multiple'    => seo_ie_catalog_import_bool( $row['multiple'] ?? '', false ),
                'filtrable'   => seo_ie_catalog_import_bool( $row['filterable'] ?? '', false ),
                'visible'     => seo_ie_catalog_import_bool( $row['visible'] ?? '', true ),
                'seo'         => seo_ie_catalog_import_bool( $row['seo'] ?? '', true ),
                'orden'       => (int) ( $row['sort_order'] ?? 0 ),
                'activo'      => true,
            ],
            'catalog_csv_import'
        );
        seo_ie_catalog_import_created( $log, $line, 'attribute', "Atributo {$slug} ({$type})", false );
        $log['correctos']++;
        return true;
    } catch ( Throwable $e ) {
        seo_ie_catalog_import_error( $log, $line, 'Atributo ' . $slug . ': ' . $e->getMessage() );
        return false;
    }
}

/** Procesa término de atributo. */
function seo_ie_catalog_import_attribute_term_row( array $item, &$log, $dry_run ) {
    global $wpdb;
    $row  = $item['row'];
    $line = $item['line'];
    $attribute_slug = seo_ie_catalog_import_attribute_slug( $row['attribute_slug'] ?? '', $row['attribute_name'] ?? '' );
    $term_name = sanitize_text_field( trim( (string) ( $row['term_name'] ?? $row['label'] ?? '' ) ) );
    $term_slug = sanitize_title( trim( (string) ( $row['term_slug'] ?? '' ) ) ?: $term_name );

    if ( '' === $attribute_slug || '' === $term_name || '' === $term_slug ) {
        seo_ie_catalog_import_error( $log, $line, 'attribute_term requiere attribute_slug y term_name/term_slug.' );
        return false;
    }

    $attribute = seo_ie_catalog_import_get_attribute( $attribute_slug );
    if ( ! $attribute || empty( $attribute['activo'] ) ) {
        if ( $dry_run && ! empty( $log['_prospective_attributes'][ $attribute_slug ] ) ) {
            $attribute = [ 'id' => 0, 'slug' => $attribute_slug, 'tipo' => (string) $log['_prospective_attributes'][ $attribute_slug ], 'activo' => 1 ];
        } else {
            seo_ie_catalog_import_error( $log, $line, "El atributo «{$attribute_slug}» no existe o no está activo." );
            return false;
        }
    }
    if ( 'termino' !== (string) $attribute['tipo'] ) {
        seo_ie_catalog_import_error( $log, $line, "«{$attribute_slug}» no es un atributo de tipo termino." );
        return false;
    }

    if ( (int) $attribute['id'] > 0 ) {
        $existing = seo_ie_catalog_import_get_attribute_term( (int) $attribute['id'], $term_slug );
        if ( $existing ) {
            if ( empty( $existing['activo'] ) ) {
                seo_ie_catalog_import_error( $log, $line, "El término «{$term_slug}» existe pero está inactivo en {$attribute_slug}." );
                return false;
            }
            seo_ie_catalog_import_skip( $log, $line, "Término {$attribute_slug}/{$term_slug} ya existe." );
            return true;
        }
    }

    if ( $dry_run ) {
        seo_ie_catalog_import_created( $log, $line, 'attribute_term', "Término {$attribute_slug}/{$term_slug} — {$term_name}", true );
        $log['correctos']++;
        return true;
    }

    try {
        seo_attributes_save_term(
            [
                'id'          => 0,
                'atributo_id' => (int) $attribute['id'],
                'slug'        => $term_slug,
                'nombre'      => $term_name,
                'orden'       => (int) ( $row['sort_order'] ?? 0 ),
                'activo'      => true,
            ],
            'catalog_csv_import'
        );
        seo_ie_catalog_import_created( $log, $line, 'attribute_term', "Término {$attribute_slug}/{$term_slug} — {$term_name}", false );
        $log['correctos']++;
        return true;
    } catch ( Throwable $e ) {
        seo_ie_catalog_import_error( $log, $line, "Término {$attribute_slug}/{$term_slug}: " . $e->getMessage() );
        return false;
    }
}

/** Procesa alias de término. */
function seo_ie_catalog_import_attribute_alias_row( array $item, &$log, $dry_run ) {
    global $wpdb;
    $row  = $item['row'];
    $line = $item['line'];
    $attribute_slug = seo_ie_catalog_import_attribute_slug( $row['attribute_slug'] ?? '', $row['attribute_name'] ?? '' );
    $term_slug = sanitize_title( trim( (string) ( $row['term_slug'] ?? '' ) ) ?: trim( (string) ( $row['term_name'] ?? '' ) ) );
    $alias = sanitize_text_field( trim( (string) ( $row['alias'] ?? '' ) ) );

    if ( '' === $attribute_slug || '' === $term_slug || '' === $alias ) {
        seo_ie_catalog_import_error( $log, $line, 'attribute_alias requiere attribute_slug, term_slug y alias.' );
        return false;
    }

    $attribute = seo_ie_catalog_import_get_attribute( $attribute_slug );
    if ( ! $attribute || empty( $attribute['activo'] ) ) {
        if ( $dry_run && ! empty( $log['_prospective_attributes'][ $attribute_slug ] ) ) {
            $attribute = [ 'id' => 0, 'slug' => $attribute_slug, 'tipo' => (string) $log['_prospective_attributes'][ $attribute_slug ], 'activo' => 1 ];
        } else {
            seo_ie_catalog_import_error( $log, $line, "El atributo «{$attribute_slug}» no existe o no está activo." );
            return false;
        }
    }
    if ( 'termino' !== (string) $attribute['tipo'] ) {
        seo_ie_catalog_import_error( $log, $line, "Los aliases masivos requieren un atributo de tipo termino: {$attribute_slug}." );
        return false;
    }

    $term = null;
    if ( (int) $attribute['id'] > 0 ) {
        $term = seo_ie_catalog_import_get_attribute_term( (int) $attribute['id'], $term_slug );
    }
    if ( ! $term ) {
        if ( $dry_run && ! empty( $log['_prospective_terms'][ $attribute_slug . '|' . $term_slug ] ) ) {
            $term = [ 'id' => 0, 'slug' => $term_slug, 'activo' => 1 ];
        } else {
            seo_ie_catalog_import_error( $log, $line, "El término {$attribute_slug}/{$term_slug} no existe o no está activo." );
            return false;
        }
    }

    if ( (int) $attribute['id'] > 0 ) {
        $aliases_table = $wpdb->prefix . 'sql_atributos_aliases';
        $existing_id = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM {$aliases_table} WHERE atributo_id=%d AND LOWER(alias)=LOWER(%s) LIMIT 1",
                (int) $attribute['id'],
                $alias
            )
        );
        if ( $existing_id > 0 ) {
            seo_ie_catalog_import_skip( $log, $line, "Alias «{$alias}» ya existe en {$attribute_slug}." );
            return true;
        }
    }

    if ( $dry_run ) {
        seo_ie_catalog_import_created( $log, $line, 'attribute_alias', "Alias {$attribute_slug}/{$term_slug}: {$alias}", true );
        $log['correctos']++;
        return true;
    }

    try {
        seo_attributes_add_alias( (int) $attribute['id'], (int) $term['id'], $alias, 'catalog_csv_import' );
        seo_ie_catalog_import_created( $log, $line, 'attribute_alias', "Alias {$attribute_slug}/{$term_slug}: {$alias}", false );
        $log['correctos']++;
        return true;
    } catch ( Throwable $e ) {
        seo_ie_catalog_import_error( $log, $line, "Alias «{$alias}»: " . $e->getMessage() );
        return false;
    }
}

/** Procesa product_tag de WooCommerce. */
function seo_ie_catalog_import_product_tag_row( array $item, &$log, $dry_run ) {
    $row  = $item['row'];
    $line = $item['line'];
    $name = sanitize_text_field( trim( (string) ( $row['label'] ?? $row['term_name'] ?? '' ) ) );
    $slug = sanitize_title( trim( (string) ( $row['slug'] ?? $row['term_slug'] ?? '' ) ) ?: $name );
    if ( '' === $name || '' === $slug ) {
        seo_ie_catalog_import_error( $log, $line, 'product_tag requiere label (nombre) y un slug válido.' );
        return false;
    }

    if ( ! taxonomy_exists( 'product_tag' ) ) {
        seo_ie_catalog_import_error( $log, $line, 'La taxonomía product_tag no está disponible.' );
        return false;
    }

    $existing = get_term_by( 'slug', $slug, 'product_tag' );
    if ( ! $existing ) {
        $existing = get_term_by( 'name', $name, 'product_tag' );
    }
    if ( $existing && ! is_wp_error( $existing ) ) {
        seo_ie_catalog_import_skip( $log, $line, "product_tag «{$name}» ya existe." );
        return true;
    }

    if ( $dry_run ) {
        seo_ie_catalog_import_created( $log, $line, 'product_tag', "product_tag {$name} ({$slug})", true );
        $log['correctos']++;
        return true;
    }

    $created = wp_insert_term( $name, 'product_tag', [ 'slug' => $slug ] );
    if ( is_wp_error( $created ) ) {
        seo_ie_catalog_import_error( $log, $line, "No se pudo crear product_tag «{$name}»: " . $created->get_error_message() );
        return false;
    }
    seo_ie_catalog_import_created( $log, $line, 'product_tag', "product_tag {$name} ({$slug})", false );
    $log['correctos']++;
    return true;
}

/**
 * Importa altas canónicas desde CSV. Modo deliberadamente add-only:
 * no actualiza, no desactiva, no elimina y nunca utiliza IDs externos.
 *
 * @return void
 */
function seo_ie_import_required_catalogs_csv() {
    if ( ! isset( $_POST['seo_import_required_catalogs'] ) ) {
        return;
    }

    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( esc_html__( 'No tienes permisos para importar vocabulario y atributos.', 'seo-system' ) );
    }

    check_admin_referer( 'seo_import_required_catalogs_csv', 'seo_import_required_catalogs_nonce' );

    if ( empty( $_FILES['required_catalogs_csv']['tmp_name'] ) || ! is_uploaded_file( $_FILES['required_catalogs_csv']['tmp_name'] ) ) {
        wp_die( esc_html__( 'No se ha recibido un CSV de catálogos válido.', 'seo-system' ) );
    }

    $handle = fopen( $_FILES['required_catalogs_csv']['tmp_name'], 'r' );
    if ( false === $handle ) {
        wp_die( esc_html__( 'No se pudo abrir el CSV de catálogos.', 'seo-system' ) );
    }

    $header = seo_ie_read_csv_row( $handle );
    if ( false === $header ) {
        fclose( $handle );
        wp_die( esc_html__( 'El CSV de catálogos está vacío.', 'seo-system' ) );
    }

    $header = array_map(
        static function ( $value ) {
            $value = seo_ie_csv_to_utf8( (string) $value );
            $value = preg_replace( '/^\xEF\xBB\xBF/', '', $value );
            return sanitize_key( trim( $value ) );
        },
        $header
    );

    if ( ! in_array( 'tipo_registro', $header, true ) && ! in_array( 'tabla', $header, true ) ) {
        fclose( $handle );
        wp_die( esc_html__( 'El CSV debe contener la columna tipo_registro (o tabla). Usa como plantilla el export de catálogos obligatorios.', 'seo-system' ) );
    }

    $dry_run = ! empty( $_POST['required_catalogs_dry_run'] );
    $log = [
        'operacion'      => $dry_run ? 'Simulación de altas de vocabulario y atributos' : 'Altas de vocabulario y atributos',
        'archivo'        => sanitize_file_name( $_FILES['required_catalogs_csv']['name'] ),
        'procesados'     => 0,
        'correctos'      => 0,
        'creados'        => 0,
        'omitidos'       => 0,
        'advertencias'   => 0,
        'errores'        => 0,
        'simulacion'     => $dry_run ? 1 : 0,
        'detalles'       => [],
        'altas_por_tipo' => [],
        '_prospective_roles'      => [],
        '_prospective_attributes' => [],
        '_prospective_terms'      => [],
    ];

    $items = [];
    $line = 1;
    while ( false !== ( $csv_row = seo_ie_read_csv_row( $handle ) ) ) {
        $line++;
        if ( empty( array_filter( $csv_row, static fn( $value ) => '' !== trim( (string) $value ) ) ) ) {
            continue;
        }
        $log['procesados']++;
        $row = seo_ie_build_csv_row( $header, $csv_row );
        $type = seo_ie_catalog_import_row_type( $row );
        if ( '' === $type ) {
            seo_ie_catalog_import_error( $log, $line, 'No se reconoce tipo_registro/tabla.' );
            continue;
        }
        $items[] = [ 'line' => $line, 'type' => $type, 'row' => $row ];

        // Índices prospectivos para que la simulación pueda validar dependencias
        // creadas dentro del mismo fichero sin escribir en base de datos.
        if ( 'vocabulary' === $type && 'rol' === sanitize_key( (string) ( $row['semantic_group'] ?? '' ) ) ) {
            $slug = seo_ie_catalog_import_semantic_slug( $row['slug'] ?? '', $row['label'] ?? '' );
            if ( '' !== $slug ) {
                $log['_prospective_roles'][ $slug ] = true;
            }
        }
        if ( 'attribute' === $type ) {
            $slug = seo_ie_catalog_import_attribute_slug( $row['attribute_slug'] ?? $row['slug'] ?? '', $row['attribute_name'] ?? $row['label'] ?? '' );
            $type_value = sanitize_key( (string) ( $row['attribute_type'] ?? 'texto' ) );
            if ( '' !== $slug ) {
                $log['_prospective_attributes'][ $slug ] = $type_value;
            }
        }
        if ( 'attribute_term' === $type ) {
            $aslug = seo_ie_catalog_import_attribute_slug( $row['attribute_slug'] ?? '', $row['attribute_name'] ?? '' );
            $tslug = sanitize_title( trim( (string) ( $row['term_slug'] ?? '' ) ) ?: trim( (string) ( $row['term_name'] ?? '' ) ) );
            if ( '' !== $aslug && '' !== $tslug ) {
                $log['_prospective_terms'][ $aslug . '|' . $tslug ] = true;
            }
        }
    }
    fclose( $handle );

    $maps_by_type = [];
    foreach ( $items as $item ) {
        if ( 'type_role_map' !== $item['type'] ) {
            continue;
        }
        $type_slug = seo_ie_catalog_import_semantic_slug( $item['row']['type_slug'] ?? '', $item['row']['type_label'] ?? '' );
        if ( '' !== $type_slug ) {
            $maps_by_type[ $type_slug ][] = $item;
        }
    }

    // Dependencias: ROL primero; vocabulario no-TIPO; TIPO + ROL; mapeos
    // sueltos; definiciones de atributos; términos; aliases; product_tag.
    foreach ( $items as $item ) {
        if ( 'vocabulary' !== $item['type'] || 'rol' !== sanitize_key( (string) ( $item['row']['semantic_group'] ?? '' ) ) ) {
            continue;
        }
        seo_ie_catalog_import_vocabulary_row( $item, $log, $dry_run );
    }

    foreach ( $items as $item ) {
        if ( 'vocabulary' !== $item['type'] ) {
            continue;
        }
        $group = sanitize_key( (string) ( $item['row']['semantic_group'] ?? '' ) );
        if ( in_array( $group, [ 'rol', 'tipo' ], true ) ) {
            continue;
        }
        seo_ie_catalog_import_vocabulary_row( $item, $log, $dry_run );
    }

    $handled_maps = [];
    foreach ( $items as $item ) {
        if ( 'vocabulary' !== $item['type'] || 'tipo' !== sanitize_key( (string) ( $item['row']['semantic_group'] ?? '' ) ) ) {
            continue;
        }
        seo_ie_catalog_import_type_row( $item, $maps_by_type, $log, $dry_run, $handled_maps );
    }

    foreach ( $items as $item ) {
        if ( 'type_role_map' !== $item['type'] ) {
            continue;
        }
        $type_slug = seo_ie_catalog_import_semantic_slug( $item['row']['type_slug'] ?? '', $item['row']['type_label'] ?? '' );
        if ( '' !== $type_slug && ! empty( $handled_maps[ $type_slug ] ) ) {
            // La fila de mapeo ha sido absorbida por el alta/validación del TIPO.
            $log['correctos']++;
            $log['omitidos']++;
            continue;
        }
        seo_ie_catalog_import_map_row( $item, $log, $dry_run );
    }

    foreach ( $items as $item ) {
        if ( 'attribute' === $item['type'] ) {
            seo_ie_catalog_import_attribute_row( $item, $log, $dry_run );
        }
    }
    foreach ( $items as $item ) {
        if ( 'attribute_term' === $item['type'] ) {
            seo_ie_catalog_import_attribute_term_row( $item, $log, $dry_run );
        }
    }
    foreach ( $items as $item ) {
        if ( 'attribute_alias' === $item['type'] ) {
            seo_ie_catalog_import_attribute_alias_row( $item, $log, $dry_run );
        }
    }
    foreach ( $items as $item ) {
        if ( 'product_tag' === $item['type'] ) {
            seo_ie_catalog_import_product_tag_row( $item, $log, $dry_run );
        }
    }

    // Tipos desconocidos: se rechazan al final para no ocultarlos.
    $supported = [ 'vocabulary', 'type_role_map', 'attribute', 'attribute_term', 'attribute_alias', 'product_tag' ];
    foreach ( $items as $item ) {
        if ( ! in_array( $item['type'], $supported, true ) ) {
            seo_ie_catalog_import_error( $log, $item['line'], 'tipo_registro «' . $item['type'] . '» no admitido.' );
        }
    }

    $summary = [];
    foreach ( [
        'vocabulary'      => 'vocabulario',
        'type_role_map'   => 'TIPO→ROL',
        'attribute'       => 'atributos',
        'attribute_term'  => 'términos de atributo',
        'attribute_alias' => 'aliases',
        'product_tag'     => 'product_tag',
    ] as $key => $label ) {
        $summary[] = $label . ': ' . (int) ( $log['altas_por_tipo'][ $key ] ?? 0 );
    }
    seo_ie_add_log_detail( $log, 'Resumen de altas' . ( $dry_run ? ' previstas' : '' ) . ': ' . implode( ' · ', $summary ) . '.' );
    seo_ie_add_log_detail( $log, 'Modo seguro add-only: no se han actualizado, eliminado ni reactivado registros existentes; los IDs del CSV se ignoran para las altas.' );

    unset( $log['_prospective_roles'], $log['_prospective_attributes'], $log['_prospective_terms'] );
    seo_ie_store_log( $log );

    wp_safe_redirect(
        add_query_arg(
            [
                'seo_ie_tab'      => 'wordpress',
                'seo_ie_imported' => $dry_run ? 'catalogs-dry-run' : 'catalogs',
            ],
            admin_url( 'admin.php?page=seo-import-export' )
        )
    );
    exit;
}

/**
 * Tarjeta de interfaz del importador de altas canónicas.
 *
 * @return void
 */
function seo_ie_render_required_catalogs_import_card() {
    ?>
    <div class="card" style="max-width:none;padding:20px;">
        <h2>Importar vocabulario y atributos</h2>
        <p>
            Alta masiva <strong>segura</strong> de los valores que deben existir antes de importar productos.
            No usa JSON: acepta el mismo CSV plano de <strong>Catálogos obligatorios</strong> y resuelve las relaciones por <code>slug</code>.
        </p>
        <p>
            Puedes subir el export completo con filas nuevas añadidas, o un CSV pequeño que conserve las mismas columnas.
            Para nuevas filas deja los IDs vacíos. El orden no importa: primero se crean vocabularios/ROL, después TIPO→ROL,
            atributos, términos, aliases y finalmente <code>product_tag</code>.
        </p>
        <div style="padding:10px 12px;border-left:4px solid #00a32a;background:#f0fff4;margin:12px 0;">
            <strong>Protección:</strong> este importador solo da de alta faltantes. No modifica, desactiva, reactiva ni elimina valores existentes.
            Si detecta un conflicto (por ejemplo un TIPO ya asociado a otro ROL), rechaza esa fila.
        </div>
        <form method="post" enctype="multipart/form-data">
            <?php wp_nonce_field( 'seo_import_required_catalogs_csv', 'seo_import_required_catalogs_nonce' ); ?>
            <input type="file" name="required_catalogs_csv" accept=".csv,text/csv" required>
            <label style="display:block;margin:12px 0;padding:10px;border-left:4px solid #72aee6;background:#f0f6fc;">
                <input type="checkbox" name="required_catalogs_dry_run" value="1" checked>
                <strong>Simular primero</strong>: valida todas las altas y dependencias sin escribir nada.
            </label>
            <p class="description">
                Filas soportadas: <code>vocabulary</code>, <code>type_role_map</code>, <code>attribute</code>,
                <code>attribute_term</code>, <code>attribute_alias</code> y <code>product_tag</code>.
                Para un TIPO nuevo indica <code>role_slug</code> en la misma fila o añade su fila <code>type_role_map</code>.
            </p>
            <p><button type="submit" name="seo_import_required_catalogs" value="1" class="button button-primary">Procesar altas de vocabulario</button></p>
        </form>
    </div>
    <?php
}
