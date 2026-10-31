<?php

defined( 'ABSPATH' ) || exit;

final class TCV_IO {
    public static function boot() {
        add_action( 'admin_post_tcv_export_labels', array( __CLASS__, 'export_labels' ) );
        add_action( 'admin_post_tcv_export_attributes', array( __CLASS__, 'export_attributes' ) );
        add_action( 'admin_post_tcv_import', array( __CLASS__, 'import' ) );
    }

    private static function guard() {
        if ( ! current_user_can( 'manage_woocommerce' ) && ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'No tienes permisos para gestionar Vocabulary.', 'taxocommerce-vocabulary' ) );
        }
    }

    private static function csv_headers( $filename, $headers ) {
        nocache_headers();
        header( 'X-Content-Type-Options: nosniff' );
        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( $filename ) . '"' );
        // CSV is a download response, not HTML. Values are encoded by csv_line().
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        echo "\xEF\xBB\xBF" . self::csv_line( $headers );
    }

    private static function csv_line( $values ) {
        $encoded = array();
        foreach ( (array) $values as $value ) {
            $value = str_replace( '"', '""', (string) $value );
            $encoded[] = '"' . $value . '"';
        }
        return implode( ';', $encoded ) . "\r\n";
    }

    private static function output_csv_row( $values ) {
        // CSV is a download response, not HTML. csv_line() quotes every field
        // and doubles embedded quotes according to RFC 4180-style CSV rules.
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        echo self::csv_line( $values );
    }

    public static function export_labels() {
        self::guard();
        check_admin_referer( 'tcv_export_labels' );
        global $wpdb;
        $t = TCV_Schema::tables();
        $headers = array( 'row_type','source_id','semantic_group','slug','label','parent_source_id','source','active','type_slug','role_slug','confidence' );
        self::csv_headers( 'taxocommerce_vocabulary_etiquetas.csv', $headers );
        $rows = $wpdb->get_results( $wpdb->prepare( "SELECT id,semantic_group,slug,label,parent_id,source,active FROM %i ORDER BY semantic_group,label", $t['vocabulary'] ), ARRAY_A );
        foreach ( (array) $rows as $r ) {
            self::output_csv_row( array( 'term',$r['id'],$r['semantic_group'],$r['slug'],$r['label'],$r['parent_id'],$r['source'],$r['active'],'','','' ) );
        }
        $maps = $wpdb->get_results( $wpdb->prepare( "SELECT tv.slug type_slug,rv.slug role_slug,m.confidence FROM %i m INNER JOIN %i tv ON tv.id=m.type_vocabulary_id INNER JOIN %i rv ON rv.id=m.role_vocabulary_id WHERE m.active=1", $t['type_role'], $t['vocabulary'], $t['vocabulary'] ), ARRAY_A );
        foreach ( (array) $maps as $r ) {
            self::output_csv_row( array( 'type_role','','','','','','','',$r['type_slug'],$r['role_slug'],$r['confidence'] ) );
        }
        exit;
    }

    public static function export_attributes() {
        self::guard();
        check_admin_referer( 'tcv_export_attributes' );
        global $wpdb;
        $t = TCV_Schema::tables();
        $headers = array( 'row_type','attribute_slug','attribute_name','group','type','unit_type','unit_base','multiple','filterable','visible','seo','sort','active','term_slug','term_name','term_sort','term_active','alias' );
        self::csv_headers( 'taxocommerce_vocabulary_atributos.csv', $headers );
        $attrs = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM %i ORDER BY orden,nombre", $t['attributes'] ), ARRAY_A );
        foreach ( (array) $attrs as $a ) {
            self::output_csv_row( array( 'attribute',$a['slug'],$a['nombre'],$a['grupo'],$a['tipo'],$a['unidad_tipo'],$a['unidad_base'],$a['multiple'],$a['filtrable'],$a['visible'],$a['seo'],$a['orden'],$a['activo'],'','','','','' ) );
        }
        $terms = $wpdb->get_results( $wpdb->prepare( "SELECT a.slug attribute_slug,t.slug term_slug,t.nombre,t.orden,t.activo FROM %i t INNER JOIN %i a ON a.id=t.atributo_id ORDER BY a.slug,t.orden,t.nombre", $t['terms'], $t['attributes'] ), ARRAY_A );
        foreach ( (array) $terms as $r ) {
            self::output_csv_row( array( 'term',$r['attribute_slug'],'','','','','','','','','','','',$r['term_slug'],$r['nombre'],$r['orden'],$r['activo'],'' ) );
        }
        $aliases = $wpdb->get_results( $wpdb->prepare( "SELECT a.slug attribute_slug,t.slug term_slug,x.alias FROM %i x INNER JOIN %i a ON a.id=x.atributo_id LEFT JOIN %i t ON t.id=x.termino_id ORDER BY a.slug,t.slug,x.alias", $t['aliases'], $t['attributes'], $t['terms'] ), ARRAY_A );
        foreach ( (array) $aliases as $r ) {
            self::output_csv_row( array( 'alias',$r['attribute_slug'],'','','','','','','','','','','',$r['term_slug'],'','','',$r['alias'] ) );
        }
        exit;
    }

    private static function read_csv( $tmp ) {
        if ( ! function_exists( 'WP_Filesystem' ) ) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }

        global $wp_filesystem;
        if ( ! WP_Filesystem() || ! $wp_filesystem ) {
            return new WP_Error( 'tcv_csv_filesystem', 'No se pudo inicializar el sistema de archivos de WordPress.' );
        }

        $contents = $wp_filesystem->get_contents( $tmp );
        if ( false === $contents ) {
            return new WP_Error( 'tcv_csv_open', 'No se pudo leer el CSV.' );
        }

        if ( 0 === strpos( $contents, "\xEF\xBB\xBF" ) ) {
            $contents = substr( $contents, 3 );
        }

        $lines = preg_split( '/\r\n|\n|\r/', $contents );
        if ( ! is_array( $lines ) || empty( $lines ) ) {
            return new WP_Error( 'tcv_csv_header', 'El CSV no contiene cabecera.' );
        }

        $header_line = array_shift( $lines );
        $headers = str_getcsv( (string) $header_line, ';' );
        if ( ! is_array( $headers ) || empty( $headers ) ) {
            return new WP_Error( 'tcv_csv_header', 'El CSV no contiene cabecera.' );
        }

        $headers = array_map( 'sanitize_key', $headers );
        $rows = array();

        foreach ( $lines as $line ) {
            if ( '' === trim( (string) $line ) ) {
                continue;
            }
            $values = str_getcsv( (string) $line, ';' );
            if ( count( $values ) !== count( $headers ) ) {
                continue;
            }
            $rows[] = array_combine( $headers, $values );
            if ( count( $rows ) > 50000 ) {
                return new WP_Error( 'tcv_csv_limit', 'El CSV supera el límite de 50.000 filas.' );
            }
        }

        return $rows;
    }

    public static function import() {
        self::guard();
        check_admin_referer( 'tcv_import' );
        $domain = isset( $_POST['domain'] ) ? sanitize_key( wp_unslash( $_POST['domain'] ) ) : '';
        // The upload's PHP temporary path must be used unchanged. Validate that
        // PHP created it, instead of sanitizing a filesystem path into another one.
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Upload metadata is checked by type, PHP upload status, size and is_uploaded_file().
        $upload = isset( $_FILES['csv'] ) && is_array( $_FILES['csv'] ) ? $_FILES['csv'] : array();
        $tmp = isset( $upload['tmp_name'] ) && is_string( $upload['tmp_name'] ) ? $upload['tmp_name'] : '';
        $size = isset( $upload['size'] ) ? absint( $upload['size'] ) : 0;
        $error = isset( $upload['error'] ) ? absint( $upload['error'] ) : UPLOAD_ERR_NO_FILE;
        if ( UPLOAD_ERR_OK !== $error || '' === $tmp || ! is_uploaded_file( $tmp )
            || 0 === $size || $size > 10 * MB_IN_BYTES ) {
            self::redirect( 'error', 'Selecciona un CSV válido de hasta 10 MB.' );
        }
        $rows = self::read_csv( $tmp );
        if ( is_wp_error( $rows ) ) {
            self::redirect( 'error', $rows->get_error_message() );
        }
        $result = 'labels' === $domain ? self::import_labels( $rows ) : ( 'attributes' === $domain ? self::import_attributes( $rows ) : new WP_Error( 'tcv_domain', 'Dominio de importación no válido.' ) );
        if ( is_wp_error( $result ) ) {
            self::redirect( 'error', $result->get_error_message() );
        }
        self::redirect( 'success', sprintf( 'Importación completada: %d creados, %d actualizados.', $result['created'], $result['updated'] ) );
    }

    private static function import_labels( $rows ) {
        global $wpdb;
        $t = TCV_Schema::tables();
        $created = 0;
        $updated = 0;
        $wpdb->query( 'START TRANSACTION' );
        try {
            foreach ( $rows as $r ) {
                if ( 'term' !== ( $r['row_type'] ?? '' ) ) {
                    continue;
                }
                $group = sanitize_key( $r['semantic_group'] ?? '' );
                $slug = sanitize_title( $r['slug'] ?? '' );
                $label = sanitize_text_field( $r['label'] ?? '' );
                if ( ! in_array( $group, TCV_Classifier::GROUPS, true ) || ! $slug || ! $label ) {
                    continue;
                }
                $id = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM %i WHERE semantic_group=%s AND slug=%s", $t['vocabulary'], $group, $slug ) );
                $data = array(
                    'label' => $label,
                    'source' => sanitize_key( $r['source'] ?: 'import' ),
                    'active' => empty( $r['active'] ) ? 0 : 1,
                    'updated_at' => current_time( 'mysql', true ),
                );
                if ( $id ) {
                    $wpdb->update( $t['vocabulary'], $data, array( 'id' => absint( $id ) ) );
                    $updated++;
                } else {
                    $data['semantic_group'] = $group;
                    $data['slug'] = $slug;
                    $data['created_at'] = current_time( 'mysql', true );
                    $wpdb->insert( $t['vocabulary'], $data );
                    $created++;
                }
            }
            foreach ( $rows as $r ) {
                if ( 'term' !== ( $r['row_type'] ?? '' ) || empty( $r['parent_source_id'] ) ) {
                    continue;
                }
                $child_id = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM %i WHERE semantic_group=%s AND slug=%s", $t['vocabulary'], sanitize_key( $r['semantic_group'] ?? '' ), sanitize_title( $r['slug'] ?? '' ) ) );
                $parent_row = null;
                foreach ( $rows as $candidate ) {
                    if ( 'term' === ( $candidate['row_type'] ?? '' ) && (string) ( $candidate['source_id'] ?? '' ) === (string) $r['parent_source_id'] ) {
                        $parent_row = $candidate;
                        break;
                    }
                }
                if ( $child_id && $parent_row ) {
                    $parent_id = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM %i WHERE semantic_group=%s AND slug=%s", $t['vocabulary'], sanitize_key( $parent_row['semantic_group'] ?? '' ), sanitize_title( $parent_row['slug'] ?? '' ) ) );
                    if ( $parent_id ) {
                        $wpdb->update( $t['vocabulary'], array( 'parent_id' => absint( $parent_id ) ), array( 'id' => absint( $child_id ) ) );
                    }
                }
            }
            foreach ( $rows as $r ) {
                if ( 'type_role' !== ( $r['row_type'] ?? '' ) ) {
                    continue;
                }
                $type_id = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM %i WHERE semantic_group='tipo' AND slug=%s", $t['vocabulary'], sanitize_title( $r['type_slug'] ?? '' ) ) );
                $role_id = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM %i WHERE semantic_group='rol' AND slug=%s", $t['vocabulary'], sanitize_title( $r['role_slug'] ?? '' ) ) );
                if ( $type_id && $role_id ) {
                    $wpdb->query( $wpdb->prepare( "INSERT INTO %i (type_vocabulary_id,role_vocabulary_id,confidence,source,active) VALUES (%d,%d,%f,'import',1) ON DUPLICATE KEY UPDATE role_vocabulary_id=VALUES(role_vocabulary_id),confidence=VALUES(confidence),source='import',active=1", $t['type_role'], $type_id, $role_id, max( 0, min( 1, (float) ( $r['confidence'] ?: 1 ) ) ) ) );
                }
            }
            $wpdb->query( 'COMMIT' );
        } catch ( Throwable $e ) {
            $wpdb->query( 'ROLLBACK' );
            return new WP_Error( 'tcv_import_labels', $e->getMessage() );
        }
        return array( 'created' => $created, 'updated' => $updated );
    }

    private static function import_attributes( $rows ) {
        global $wpdb;
        $t = TCV_Schema::tables();
        $created = 0;
        $updated = 0;
        $wpdb->query( 'START TRANSACTION' );
        try {
            foreach ( $rows as $r ) {
                if ( 'attribute' !== ( $r['row_type'] ?? '' ) ) {
                    continue;
                }
                $slug = sanitize_title( $r['attribute_slug'] ?? '' );
                $name = sanitize_text_field( $r['attribute_name'] ?? '' );
                if ( ! $slug || ! $name ) {
                    continue;
                }
                $id = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM %i WHERE slug=%s", $t['attributes'], $slug ) );
                $type = in_array( $r['type'] ?? '', array( 'texto','numero','boolean','termino','rango' ), true ) ? $r['type'] : 'texto';
                $data = array(
                    'nombre' => $name,
                    'grupo' => sanitize_text_field( $r['group'] ?? '' ),
                    'tipo' => $type,
                    'unidad_tipo' => sanitize_text_field( $r['unit_type'] ?? '' ),
                    'unidad_base' => sanitize_text_field( $r['unit_base'] ?? '' ),
                    'multiple' => empty( $r['multiple'] ) ? 0 : 1,
                    'filtrable' => empty( $r['filterable'] ) ? 0 : 1,
                    'visible' => empty( $r['visible'] ) ? 0 : 1,
                    'seo' => empty( $r['seo'] ) ? 0 : 1,
                    'orden' => intval( $r['sort'] ?? 0 ),
                    'activo' => empty( $r['active'] ) ? 0 : 1,
                );
                if ( $id ) {
                    $wpdb->update( $t['attributes'], $data, array( 'id' => absint( $id ) ) );
                    $updated++;
                } else {
                    $data['slug'] = $slug;
                    $wpdb->insert( $t['attributes'], $data );
                    $created++;
                }
            }
            foreach ( $rows as $r ) {
                if ( ! in_array( $r['row_type'] ?? '', array( 'term','alias' ), true ) ) {
                    continue;
                }
                $aid = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM %i WHERE slug=%s", $t['attributes'], sanitize_title( $r['attribute_slug'] ?? '' ) ) );
                if ( ! $aid ) {
                    continue;
                }
                if ( 'term' === $r['row_type'] ) {
                    $slug = sanitize_title( $r['term_slug'] ?? '' );
                    $name = sanitize_text_field( $r['term_name'] ?? '' );
                    if ( ! $slug || ! $name ) {
                        continue;
                    }
                    $wpdb->query( $wpdb->prepare( "INSERT INTO %i (atributo_id,slug,nombre,orden,activo) VALUES (%d,%s,%s,%d,%d) ON DUPLICATE KEY UPDATE nombre=VALUES(nombre),orden=VALUES(orden),activo=VALUES(activo)", $t['terms'], $aid, $slug, $name, intval( $r['term_sort'] ?? 0 ), empty( $r['term_active'] ) ? 0 : 1 ) );
                } else {
                    $alias = sanitize_text_field( $r['alias'] ?? '' );
                    if ( ! $alias ) {
                        continue;
                    }
                    $term_id = null;
                    if ( ! empty( $r['term_slug'] ) ) {
                        $term_id = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM %i WHERE atributo_id=%d AND slug=%s", $t['terms'], $aid, sanitize_title( $r['term_slug'] ) ) );
                    }
                    $exists = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM %i WHERE atributo_id=%d AND COALESCE(termino_id,0)=%d AND alias=%s", $t['aliases'], $aid, absint( $term_id ), $alias ) );
                    if ( ! $exists ) {
                        $wpdb->insert( $t['aliases'], array( 'atributo_id' => $aid, 'termino_id' => $term_id ?: null, 'alias' => $alias ) );
                    }
                }
            }
            $wpdb->query( 'COMMIT' );
        } catch ( Throwable $e ) {
            $wpdb->query( 'ROLLBACK' );
            return new WP_Error( 'tcv_import_attributes', $e->getMessage() );
        }
        return array( 'created' => $created, 'updated' => $updated );
    }

    private static function redirect( $status, $message ) {
        wp_safe_redirect( add_query_arg( array( 'page'=>'taxocommerce-vocabulary','tab'=>'io','tcv_status'=>$status,'tcv_message'=>rawurlencode( $message ) ), admin_url( 'admin.php' ) ) );
        exit;
    }
}
