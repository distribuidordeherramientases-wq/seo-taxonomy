<?php
defined( 'ABSPATH' ) || exit;

/**
 * Independent FAQ editor. Keeps the existing seo_faq table as source of truth.
 * Does not load seo-faq.php from the monolith or declare conflicting seo_* APIs.
 */
final class TaxoCommerce_FAQs {
    const PAGE = 'taxocommerce-faqs';
    const GROUPS = array( 'rol', 'tipo', 'aplicacion', 'plataforma', 'subtipo' );

    private static function table( $suffix ) {
        global $wpdb;
        return esc_sql( $wpdb->prefix . $suffix );
    }

    public static function install() {
        global $wpdb;
        $faq = self::table( 'seo_faq' );
        if ( self::exists( 'seo_faq' ) ) {
            self::upgrade_category();
            return;
        }
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $collation = $wpdb->get_charset_collate();
        dbDelta( "CREATE TABLE {$faq} (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            object_type smallint unsigned NOT NULL,
            object_id bigint unsigned NOT NULL,
            product_cat_id bigint unsigned NOT NULL DEFAULT 0,
            question varchar(255) NOT NULL,
            answer longtext NOT NULL,
            sort_order int unsigned NOT NULL DEFAULT 0,
            active tinyint(1) NOT NULL DEFAULT 1,
            load_count bigint unsigned NOT NULL DEFAULT 0,
            open_count bigint unsigned NOT NULL DEFAULT 0,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY object_lookup (object_type,object_id,active),
            KEY product_cat (product_cat_id),
            KEY sort_order (sort_order),
            KEY updated_at (updated_at)
        ) {$collation};" );
        update_option( 'taxocommerce_faqs_category_schema', '1', false );
    }

    public static function upgrade_category() {
        global $wpdb;
        if ( ! self::exists( 'seo_faq' ) ) { return false; }
        $table = self::table( 'seo_faq' );
        $column = $wpdb->get_var( $wpdb->prepare( 'SHOW COLUMNS FROM %i LIKE %s', $table, 'product_cat_id' ) );
        if ( ! $column ) {
            $updated = $wpdb->query( $wpdb->prepare( 'ALTER TABLE %i ADD COLUMN product_cat_id bigint unsigned NOT NULL DEFAULT 0, ADD INDEX product_cat (product_cat_id)', $table ) );
            if ( false === $updated ) { return false; }
        }
        // Only unambiguous existing category relationships are migrated automatically.
        $wpdb->query( $wpdb->prepare(
            "UPDATE %i SET product_cat_id=object_id WHERE object_type=2 AND product_cat_id=0 AND EXISTS
            (SELECT 1 FROM %i tt WHERE tt.term_id=%i.object_id AND tt.taxonomy='product_cat')",
            $table, $wpdb->term_taxonomy, $table
        ) );
        update_option( 'taxocommerce_faqs_category_schema', '1', false );
        return true;
    }

    public static function ensure_category() {
        if ( '1' !== get_option( 'taxocommerce_faqs_category_schema', '' ) ) {
            self::upgrade_category();
        }
    }

    private static function valid_category( $category_id ) {
        if ( ! absint( $category_id ) ) { return false; }
        $term = get_term( absint( $category_id ), 'product_cat' );
        return $term && ! is_wp_error( $term );
    }

    private static function exists( $suffix ) {
        global $wpdb;
        $table = self::table( $suffix );
        return $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
    }

    public static function boot() {
        add_action( 'admin_init', array( __CLASS__, 'ensure_category' ) );
        add_action( 'admin_menu', array( __CLASS__, 'menu' ), 60 );
        add_action( 'admin_post_taxocommerce_faqs_save', array( __CLASS__, 'save' ) );
        add_action( 'admin_post_taxocommerce_faqs_delete', array( __CLASS__, 'delete' ) );
        add_action( 'admin_post_taxocommerce_faqs_export', array( __CLASS__, 'export' ) );
        add_action( 'admin_post_taxocommerce_faqs_import', array( __CLASS__, 'import' ) );
    }

    public static function register_core( $core ) {
        if ( is_object( $core ) && method_exists( $core, 'register_module' ) ) {
            $core->register_module( 'faqs', array(
                'name' => 'TaxoCommerce FAQs',
                'version' => TAXOCOMMERCE_FAQS_VERSION,
                'status' => 'active',
                'description' => 'Preguntas frecuentes, etiquetas, CSV e indicadores.',
                'admin_url' => admin_url( 'admin.php?page=taxocommerce-core-content&tc_type=faqs' ),
            ) );
        }
    }

    public static function menu() {
        if ( ! function_exists( 'taxocommerce_core' ) ) {
            add_menu_page( 'TaxoCommerce FAQs', 'TaxoCommerce FAQs', 'manage_options', self::PAGE, array( __CLASS__, 'render' ), 'dashicons-editor-help', 59 );
        } else {
            // FAQs remains accessible from Contenidos and as its own Core submenu.
            add_submenu_page( 'taxocommerce-core', 'TaxoCommerce FAQs', 'FAQs', 'manage_options', self::PAGE, array( __CLASS__, 'render' ) );
        }
    }

    public static function url( $args = array() ) {
        $core = function_exists( 'taxocommerce_core' );
        return add_query_arg( array_merge( $core
            ? array( 'page' => 'taxocommerce-core-content', 'tc_type' => 'faqs' )
            : array( 'page' => self::PAGE ), $args ), admin_url( 'admin.php' ) );
    }

    private static function guard() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'No tienes permisos para gestionar FAQs.', 'taxocommerce-faqs' ) );
        }
    }

    private static function notice( $text, $error = false ) {
        set_transient( 'taxocommerce_faqs_notice_' . get_current_user_id(), array( sanitize_text_field( $text ), $error ), 60 );
        wp_safe_redirect( self::url() );
        exit;
    }

    private static function target_exists( $type, $id ) {
        $id = absint( $id );
        if ( ! $id ) { return false; }
        if ( 2 === $type ) {
            $term = get_term( $id, 'product_cat' );
            return $term && ! is_wp_error( $term );
        }
        $post = get_post( $id );
        if ( ! $post ) { return false; }
        return 1 === $type ? in_array( $post->post_type, array( 'page', 'seo_landing' ), true ) : ( 3 === $type && 'product' === $post->post_type );
    }

    private static function tags_available() {
        return self::exists( 'seo_vocabulary' ) && self::exists( 'seo_object_vocabulary' );
    }

    private static function tags( $faq_id ) {
        global $wpdb;
        $data = array();
        foreach ( self::GROUPS as $group ) { $data[ $group ] = array(); }
        if ( ! self::tags_available() || ! $faq_id ) { return $data; }
        $voc = self::table( 'seo_vocabulary' );
        $rel = self::table( 'seo_object_vocabulary' );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Fresh optional FAQ tag links; values may have just been edited in this request.
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT v.id,v.label,v.slug,v.semantic_group,r.source FROM %i r
             INNER JOIN %i v ON v.id=r.vocabulary_id
             WHERE r.object_type='faq' AND r.object_id=%d AND r.status=1 AND v.active=1",
            $rel, $voc, $faq_id
        ), ARRAY_A );
        foreach ( (array) $rows as $row ) {
            if ( isset( $data[ $row['semantic_group'] ] ) ) { $data[ $row['semantic_group'] ][] = $row; }
        }
        return $data;
    }

    private static function save_tags( $faq_id, $posted ) {
        global $wpdb;
        if ( ! self::tags_available() ) { return; }
        $voc = self::table( 'seo_vocabulary' );
        $rel = self::table( 'seo_object_vocabulary' );
        foreach ( self::GROUPS as $group ) {
            $requested = isset( $posted[ $group ] ) && is_array( $posted[ $group ] )
                ? array_values( array_unique( array_filter( array_map( 'absint', $posted[ $group ] ) ) ) ) : array();
            $requested = array_slice( $requested, 0, 50 );
            $valid = array();
            if ( $requested ) {
                $ph = implode( ',', array_fill( 0, count( $requested ), '%d' ) );
                $valid = array_map( 'intval', $wpdb->get_col( $wpdb->prepare(
                    'SELECT id FROM %i WHERE active=1 AND semantic_group=%s AND id IN (' . $ph . ')',
                    array_merge( array( $voc, $group ), $requested )
                ) ) );
            }
            $existing = self::tags( $faq_id )[ $group ];
            foreach ( $existing as $row ) {
                if ( 'manual' === $row['source'] && ! in_array( (int) $row['id'], $valid, true ) ) {
                    $wpdb->update( $rel, array( 'status' => 0, 'updated_at' => current_time( 'mysql', true ) ), array(
                        'object_type' => 'faq', 'object_id' => $faq_id, 'vocabulary_id' => (int) $row['id'], 'source' => 'manual',
                    ), array( '%d', '%s' ), array( '%s', '%d', '%d', '%s' ) );
                }
            }
            foreach ( $valid as $id ) {
                $already = $wpdb->get_row( $wpdb->prepare(
                    'SELECT source,status FROM %i WHERE object_type=%s AND object_id=%d AND vocabulary_id=%d', $rel, 'faq', $faq_id, $id
                ), ARRAY_A );
                if ( $already && 'manual' !== $already['source'] ) { continue; }
                if ( $already ) {
                    $wpdb->update( $rel, array( 'status' => 1, 'updated_at' => current_time( 'mysql', true ) ), array(
                        'object_type' => 'faq', 'object_id' => $faq_id, 'vocabulary_id' => $id,
                    ), array( '%d', '%s' ), array( '%s', '%d', '%d' ) );
                } else {
                    $wpdb->insert( $rel, array( 'object_type' => 'faq', 'object_id' => $faq_id, 'vocabulary_id' => $id,
                        'source' => 'manual', 'confidence' => 1, 'status' => 1, 'updated_at' => current_time( 'mysql', true ) ),
                        array( '%s', '%d', '%d', '%s', '%f', '%d', '%s' ) );
                }
            }
        }
    }

    public static function save() {
        self::guard();
        check_admin_referer( 'taxocommerce_faqs_save' );
        global $wpdb;
        $id = isset( $_POST['faq_id'] ) ? absint( wp_unslash( $_POST['faq_id'] ) ) : 0;
        $type = isset( $_POST['object_type'] ) ? absint( wp_unslash( $_POST['object_type'] ) ) : 0;
        $target = isset( $_POST['object_id'] ) ? absint( wp_unslash( $_POST['object_id'] ) ) : 0;
        $category = isset( $_POST['product_cat_id'] ) ? absint( wp_unslash( $_POST['product_cat_id'] ) ) : 0;
        if ( 2 === $type && ! $target ) { $target = $category; }
        $question = isset( $_POST['question'] ) ? sanitize_text_field( wp_unslash( $_POST['question'] ) ) : '';
        $answer = isset( $_POST['answer'] ) ? wp_kses_post( wp_unslash( $_POST['answer'] ) ) : '';
        $question = function_exists( 'mb_substr' ) ? mb_substr( $question, 0, 255 ) : substr( $question, 0, 255 );
        if ( ! self::valid_category( $category ) || ! in_array( $type, array( 1, 2, 3 ), true ) || ! self::target_exists( $type, $target )
            || '' === $question || '' === trim( wp_strip_all_tags( $answer ) ) ) {
            self::notice( 'Revisa la pregunta, la respuesta y el ID del destino.', true );
        }
        $table = self::table( 'seo_faq' );
        $data = array( 'object_type' => $type, 'object_id' => $target, 'product_cat_id' => $category, 'question' => $question, 'answer' => $answer,
            'sort_order' => isset( $_POST['sort_order'] ) ? absint( wp_unslash( $_POST['sort_order'] ) ) : 0,
            'active' => empty( $_POST['active'] ) ? 0 : 1, 'updated_at' => current_time( 'mysql', true ) );
        if ( $id ) {
            $exists = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE id=%d', $table, $id ) );
            if ( ! $exists ) { self::notice( 'FAQ inexistente.', true ); }
            $ok = $wpdb->update( $table, $data, array( 'id' => $id ) );
        } else {
            $data['created_at'] = current_time( 'mysql', true );
            $ok = $wpdb->insert( $table, $data );
            $id = (int) $wpdb->insert_id;
        }
        if ( false === $ok || ! $id ) { self::notice( 'Error guardando FAQ.', true ); }
        if ( isset( $_POST['vocab_present'] ) ) {
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Checked above.
            $groups = isset( $_POST['vocab'] ) && is_array( $_POST['vocab'] )
                ? map_deep( wp_unslash( $_POST['vocab'] ), 'absint' ) : array();
            self::save_tags( $id, $groups );
        }
        if ( function_exists( 'seo_faq_clear_cache' ) ) { seo_faq_clear_cache( $type, $target ); }
        self::notice( 'FAQ guardada.' );
    }

    public static function delete() {
        self::guard();
        check_admin_referer( 'taxocommerce_faqs_delete' );
        global $wpdb;
        $id = isset( $_POST['faq_id'] ) ? absint( wp_unslash( $_POST['faq_id'] ) ) : 0;
        $faq = $wpdb->get_row( $wpdb->prepare( 'SELECT id, object_type, object_id FROM %i WHERE id = %d', self::table( 'seo_faq' ), $id ) );
        if ( ! $faq ) { self::notice( 'FAQ inexistente.', true ); }
        if ( false === $wpdb->delete( self::table( 'seo_faq' ), array( 'id' => $id ), array( '%d' ) ) ) { self::notice( 'Error eliminando FAQ.', true ); }
        if ( self::tags_available() ) {
            $wpdb->delete( self::table( 'seo_object_vocabulary' ), array( 'object_type' => 'faq', 'object_id' => $id ), array( '%s', '%d' ) );
        }
        if ( function_exists( 'seo_faq_clear_cache' ) ) { seo_faq_clear_cache( (int) $faq->object_type, (int) $faq->object_id ); }
        self::notice( 'FAQ eliminada.' );
    }

    private static function csv_line( $values ) {
        $encoded = array();
        foreach ( (array) $values as $value ) {
            $value = str_replace( '"', '""', (string) $value );
            $encoded[] = '"' . $value . '"';
        }
        return implode( ',', $encoded ) . "\r\n";
    }

    private static function output_csv_row( $values ) {
        // CSV is an authenticated download response, not HTML. csv_line()
        // quotes every field and doubles embedded quotation marks.
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        echo self::csv_line( $values );
    }

    /**
     * Parse a bounded CSV string while preserving delimiters and newlines
     * inside quoted fields. Import files are capped at 2 MB before parsing.
     */
    private static function parse_csv_rows( $contents ) {
        $rows = array();
        $row = array();
        $field = '';
        $quoted = false;
        $length = strlen( (string) $contents );

        for ( $i = 0; $i < $length; $i++ ) {
            $char = $contents[ $i ];

            if ( $quoted ) {
                if ( '"' === $char ) {
                    if ( $i + 1 < $length && '"' === $contents[ $i + 1 ] ) {
                        $field .= '"';
                        $i++;
                    } else {
                        $quoted = false;
                    }
                } else {
                    $field .= $char;
                }
                continue;
            }

            if ( '"' === $char && '' === $field ) {
                $quoted = true;
            } elseif ( ',' === $char ) {
                $row[] = $field;
                $field = '';
            } elseif ( "\r" === $char || "\n" === $char ) {
                $row[] = $field;
                $field = '';
                if ( 1 !== count( $row ) || '' !== (string) $row[0] ) {
                    $rows[] = $row;
                }
                $row = array();
                if ( "\r" === $char && $i + 1 < $length && "\n" === $contents[ $i + 1 ] ) {
                    $i++;
                }
            } else {
                $field .= $char;
            }
        }

        if ( $quoted ) {
            return new WP_Error( 'taxocommerce_faqs_csv_quotes', 'El CSV contiene un campo entrecomillado sin cerrar.' );
        }
        if ( '' !== $field || $row ) {
            $row[] = $field;
            $rows[] = $row;
        }
        return $rows;
    }

    public static function export() {
        self::guard();
        check_admin_referer( 'taxocommerce_faqs_export' );
        global $wpdb;
        $table = self::table( 'seo_faq' );
        $filename = 'taxocommerce_faqs_' . gmdate( 'Ymd_His' ) . '.csv';
        nocache_headers();
        header( 'X-Content-Type-Options: nosniff' );
        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( $filename ) . '"' );
        // UTF-8 BOM for spreadsheet applications.
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        echo "\xEF\xBB\xBF";
        self::output_csv_row( array( 'id', 'object_type', 'object_id', 'product_cat_id', 'question', 'answer', 'sort_order', 'active', 'rol', 'tipo', 'aplicacion', 'plataforma', 'subtipo' ) );

        $cursor = 0;
        do {
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    'SELECT id, object_type, object_id, product_cat_id, question, answer, sort_order, active FROM %i WHERE id > %d ORDER BY id ASC LIMIT 200',
                    $table,
                    $cursor
                ),
                ARRAY_A
            );
            foreach ( (array) $rows as $row ) {
                $cursor = (int) $row['id'];
                $tags = self::tags( $cursor );
                $values = array_values( $row );
                foreach ( self::GROUPS as $group ) {
                    $values[] = implode( '|', array_map( static function ( $tag ) { return $tag['slug']; }, $tags[ $group ] ) );
                }
                $values = array_map(
                    static function ( $value ) {
                        $value = (string) $value;
                        return preg_match( '/^[=+@\x2d]/', $value ) ? "'" . $value : $value;
                    },
                    $values
                );
                self::output_csv_row( $values );
            }
        } while ( count( (array) $rows ) === 200 );
        exit;
    }

    public static function import() {
        self::guard();
        check_admin_referer( 'taxocommerce_faqs_import' );

        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Uploaded metadata is validated with is_uploaded_file(), UPLOAD_ERR_OK and size limits before reading; sanitizing tmp_name can invalidate server paths.
        $upload = isset( $_FILES['faqs_csv'] ) && is_array( $_FILES['faqs_csv'] )
            ? $_FILES['faqs_csv']
            : array();
        $tmp_name = isset( $upload['tmp_name'] ) ? (string) $upload['tmp_name'] : '';
        $size = isset( $upload['size'] ) ? absint( $upload['size'] ) : 0;
        $error = isset( $upload['error'] ) ? absint( $upload['error'] ) : UPLOAD_ERR_NO_FILE;
        if ( UPLOAD_ERR_OK !== $error || '' === $tmp_name || ! is_uploaded_file( $tmp_name ) || $size < 1 || $size > 2 * MB_IN_BYTES ) {
            self::notice( 'Selecciona un CSV válido de hasta 2 MB.', true );
        }

        if ( ! function_exists( 'WP_Filesystem' ) ) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }
        global $wp_filesystem;
        if ( ! WP_Filesystem() || ! $wp_filesystem ) {
            self::notice( 'No se pudo inicializar el sistema de archivos de WordPress.', true );
        }

        $contents = $wp_filesystem->get_contents( $tmp_name );
        if ( false === $contents ) {
            self::notice( 'No se pudo leer el CSV.', true );
        }
        if ( 0 === strpos( $contents, "\xEF\xBB\xBF" ) ) {
            $contents = substr( $contents, 3 );
        }

        $parsed = self::parse_csv_rows( $contents );
        if ( is_wp_error( $parsed ) || ! $parsed ) {
            self::notice( is_wp_error( $parsed ) ? $parsed->get_error_message() : 'El CSV está vacío.', true );
        }

        $header = array_map( 'trim', (array) array_shift( $parsed ) );
        $needed = array( 'id', 'object_type', 'object_id', 'product_cat_id', 'question', 'answer', 'sort_order', 'active' );
        if ( array_diff( $needed, $header ) ) {
            self::notice( 'Faltan columnas CSV requeridas.', true );
        }

        global $wpdb;
        $table = self::table( 'seo_faq' );
        $created = 0;
        $updated = 0;
        $errors = 0;
        $seen = 0;

        foreach ( $parsed as $row ) {
            if ( $seen >= 500 ) {
                break;
            }
            $seen++;
            if ( count( $row ) !== count( $header ) ) {
                $errors++;
                continue;
            }
            $data = array_combine( $header, $row );
            $type = absint( $data['object_type'] );
            $target = absint( $data['object_id'] );
            $category = absint( $data['product_cat_id'] );
            if ( 2 === $type && ! $target ) {
                $target = $category;
            }
            $question = sanitize_text_field( $data['question'] );
            $answer = wp_kses_post( $data['answer'] );
            if ( ! self::valid_category( $category ) || ! in_array( $type, array( 1, 2, 3 ), true ) || ! self::target_exists( $type, $target )
                || '' === trim( $question ) || '' === trim( wp_strip_all_tags( $answer ) ) || strlen( $question ) > 255 ) {
                $errors++;
                continue;
            }

            $payload = array(
                'object_type' => $type,
                'object_id' => $target,
                'product_cat_id' => $category,
                'question' => $question,
                'answer' => $answer,
                'sort_order' => absint( $data['sort_order'] ),
                'active' => (int) ( '1' === trim( $data['active'] ) ),
                'updated_at' => current_time( 'mysql', true ),
            );
            $id = absint( $data['id'] );
            $existing = $id
                ? (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE id = %d', $table, $id ) )
                : 0;

            if ( $existing ) {
                $ok = $wpdb->update( $table, $payload, array( 'id' => $existing ) );
                if ( false === $ok ) {
                    $errors++;
                    continue;
                }
                $updated++;
                $id = $existing;
            } else {
                $payload['created_at'] = current_time( 'mysql', true );
                $ok = $wpdb->insert( $table, $payload );
                if ( false === $ok ) {
                    $errors++;
                    continue;
                }
                $id = (int) $wpdb->insert_id;
                $created++;
            }

            if ( self::tags_available() ) {
                $groups = array();
                foreach ( self::GROUPS as $group ) {
                    $slugs = empty( $data[ $group ] )
                        ? array()
                        : array_slice( array_filter( array_map( 'sanitize_title', explode( '|', $data[ $group ] ) ) ), 0, 50 );
                    if ( ! $slugs ) {
                        $groups[ $group ] = array();
                        continue;
                    }
                    $voc = self::table( 'seo_vocabulary' );
                    $ph = implode( ',', array_fill( 0, count( $slugs ), '%s' ) );
                    $groups[ $group ] = $wpdb->get_col(
                        $wpdb->prepare(
                            'SELECT id FROM %i WHERE active=1 AND semantic_group=%s AND slug IN (' . $ph . ')',
                            array_merge( array( $voc, $group ), $slugs )
                        )
                    );
                }
                self::save_tags( $id, $groups );
            }
        }

        self::notice(
            sprintf( 'Importación: %d creadas, %d actualizadas, %d errores. Máximo 500 filas por archivo.', $created, $updated, $errors ),
            $errors > 0
        );
    }

    private static function category_name( $id ) {
        $term = $id ? get_term( $id, 'product_cat' ) : null;
        return $term && ! is_wp_error( $term ) ? $term->name : 'Sin categoría';
    }

    public static function render() {
        self::guard();
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin tab selector.
        $tab = isset( $_GET['tc_faq_tab'] ) && is_string( $_GET['tc_faq_tab'] ) ? sanitize_key( wp_unslash( $_GET['tc_faq_tab'] ) ) : 'faqs';
        echo '<div class="wrap">';
        echo '<nav class="nav-tab-wrapper" aria-label="' . esc_attr__( 'Secciones de FAQs', 'taxocommerce-faqs' ) . '">';
        foreach ( array( 'faqs' => __( 'FAQs', 'taxocommerce-faqs' ), 'auto-test' => __( 'Auto Test', 'taxocommerce-faqs' ), 'manual' => __( 'Manual', 'taxocommerce-faqs' ) ) as $key => $label ) {
            echo '<a class="nav-tab ' . ( $tab === $key ? 'nav-tab-active' : '' ) . '" href="' . esc_url( self::url( array( 'tc_faq_tab' => $key ) ) ) . '">' . esc_html( $label ) . '</a>';
        }
        echo '</nav>';
        if ( 'auto-test' === $tab ) {
            TaxoCommerce_FAQs_Auto_Test::render();
            echo '</div>';
            return;
        }
        if ( 'manual' === $tab ) {
            TaxoCommerce_FAQs_Manual::render();
            echo '</div>';
            return;
        }
        if ( ! self::exists( 'seo_faq' ) ) {
            echo '<h1>FAQs</h1><p>' . esc_html__( 'Falta la tabla de FAQs; desactiva y reactiva TaxoCommerce FAQs para instalarla.', 'taxocommerce-faqs' ) . '</p></div>';
            return;
        }
        global $wpdb;
        self::ensure_category();
        $table = self::table( 'seo_faq' );
        $id = isset( $_GET['faq_id'] ) ? absint( wp_unslash( $_GET['faq_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $editing = $id ? $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', $table, $id ), ARRAY_A ) : null;
        $summary = $wpdb->get_row( $wpdb->prepare(
            "SELECT COUNT(*) total, SUM(active=1) active_count,SUM(active=0) inactive_count,COUNT(DISTINCT CONCAT(object_type,':',object_id)) destinations,SUM(open_count) opens,
            COUNT(DISTINCT NULLIF(product_cat_id,0)) categories,SUM(product_cat_id=0) uncategorized FROM %i",
            $table
        ), ARRAY_A );
        $page = isset( $_GET['faq_paged'] ) ? max( 1, absint( wp_unslash( $_GET['faq_paged'] ) ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $search = isset( $_GET['faq_search'] ) ? sanitize_text_field( wp_unslash( $_GET['faq_search'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $filter_category = isset( $_GET['faq_category'] ) ? absint( wp_unslash( $_GET['faq_category'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only category filter.
        // Use one static, fully prepared query; both filters are optional.
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM %i WHERE (%s = '' OR question LIKE %s)
                  AND (%d = 0 OR product_cat_id = %d)
                  ORDER BY id DESC LIMIT 25 OFFSET %d",
                $table,
                $search,
                '%' . $wpdb->esc_like( $search ) . '%',
                $filter_category,
                $filter_category,
                ( $page - 1 ) * 25
            ),
            ARRAY_A
        );
        $notice = get_transient( 'taxocommerce_faqs_notice_' . get_current_user_id() );
        echo '<h2>FAQs</h2><p>Preguntas frecuentes asociadas a hubs, categorías o productos. Datos compartidos con TaxoCommerce SEO.</p>';
        if ( $notice ) { delete_transient( 'taxocommerce_faqs_notice_' . get_current_user_id() ); echo '<div class="notice notice-' . ( $notice[1] ? 'error' : 'success' ) . '"><p>' . esc_html( $notice[0] ) . '</p></div>'; }
        echo '<div style="display:flex;gap:10px;flex-wrap:wrap">';
        foreach ( array( 'Total' => $summary['total'], 'Activas' => $summary['active_count'], 'Desactivadas' => $summary['inactive_count'], 'Destinos' => $summary['destinations'], 'Categorías' => $summary['categories'], 'Sin categoría' => $summary['uncategorized'], 'Aperturas' => $summary['opens'] ) as $label => $value ) {
            echo '<div class="card" style="padding:12px;min-width:120px;margin:0"><strong style="font-size:22px">' . esc_html( number_format_i18n( absint( $value ) ) ) . '</strong><br>' . esc_html( $label ) . '</div>';
        }
        echo '</div><h2>' . ( $editing ? 'Editar FAQ #' . absint( $id ) : 'Nueva FAQ' ) . '</h2>';
        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="taxocommerce_faqs_save"><input type="hidden" name="faq_id" value="' . absint( $id ) . '">';
        wp_nonce_field( 'taxocommerce_faqs_save' );
        echo '<table class="form-table"><tr><th>Categoría de producto *</th><td>';
        wp_dropdown_categories( array( 'taxonomy' => 'product_cat', 'name' => 'product_cat_id',
            'selected' => absint( $editing['product_cat_id'] ?? 0 ), 'show_option_none' => 'Seleccionar categoría',
            'option_none_value' => '0', 'hide_empty' => false, 'hierarchical' => true, 'required' => true ) );
        echo '<p class="description">Obligatoria. Las FAQs anteriores sin categoría aparecen en el KPI correspondiente.</p></td></tr><tr><th>Destino</th><td><select name="object_type">';
        foreach ( array( 2 => 'Categoría', 1 => 'Hub / Página', 3 => 'Producto' ) as $type => $label ) { echo '<option value="' . absint( $type ) . '" ' . selected( (int) ( $editing['object_type'] ?? 2 ), $type, false ) . '>' . esc_html( $label ) . '</option>'; }
        echo '</select> <label>ID del destino (opcional para categoría) <input type="number" min="1" name="object_id" value="' . esc_attr( (string) ( $editing['object_id'] ?? '' ) ) . '"></label></td></tr>';
        echo '<tr><th>Pregunta</th><td><input class="large-text" maxlength="255" name="question" required value="' . esc_attr( (string) ( $editing['question'] ?? '' ) ) . '"></td></tr>';
        echo '<tr><th>Respuesta</th><td><textarea class="large-text" rows="5" name="answer" required>' . esc_textarea( (string) ( $editing['answer'] ?? '' ) ) . '</textarea></td></tr>';
        echo '<tr><th>Orden</th><td><input type="number" name="sort_order" min="0" value="' . absint( $editing['sort_order'] ?? 0 ) . '"></td></tr>';
        echo '<tr><th>Publicación</th><td><label><input type="checkbox" name="active" value="1" ' . checked( (int) ( $editing['active'] ?? 1 ), 1, false ) . '> FAQ activa</label></td></tr>';
        echo '</table>';
        if ( self::tags_available() ) {
            $selected = $editing ? self::tags( $id ) : array();
            echo '<h3>Etiquetas de Vocabulary</h3><input type="hidden" name="vocab_present" value="1"><p>Selecciona las etiquetas existentes. No se crean etiquetas nuevas.</p>';
            $voc = self::table( 'seo_vocabulary' );
            foreach ( self::GROUPS as $group ) {
                $options = $wpdb->get_results( $wpdb->prepare( 'SELECT id,label FROM %i WHERE active=1 AND semantic_group=%s ORDER BY label ASC LIMIT 300', $voc, $group ), ARRAY_A );
                $ids = array_map( 'intval', array_column( $selected[ $group ] ?? array(), 'id' ) );
                echo '<p><label><strong>' . esc_html( strtoupper( $group ) ) . '</strong><br><select multiple name="vocab[' . esc_attr( $group ) . '][]" style="min-width:300px;max-width:100%;" size="4">';
                foreach ( (array) $options as $option ) {
                    echo '<option value="' . absint( $option['id'] ) . '"' . selected( in_array( (int) $option['id'], $ids, true ), true, false ) . '>' . esc_html( $option['label'] ) . '</option>';
                }
                echo '</select></label></p>';
            }
        } else {
            echo '<p class="notice notice-info">Vocabulary no está disponible; se conservarán las etiquetas existentes.</p>';
        }
        submit_button( $editing ? 'Guardar FAQ' : 'Crear FAQ' );
        echo '</form><hr><h2>Inventario</h2><form method="get"><input type="hidden" name="page" value="' . esc_attr( function_exists( 'taxocommerce_core' ) ? 'taxocommerce-core-content' : self::PAGE ) . '">';
        if ( function_exists( 'taxocommerce_core' ) ) { echo '<input type="hidden" name="tc_type" value="faqs">'; }
        echo '<input type="search" name="faq_search" value="' . esc_attr( $search ) . '" placeholder="Buscar pregunta"> ';
        wp_dropdown_categories( array( 'taxonomy' => 'product_cat', 'name' => 'faq_category', 'selected' => $filter_category,
            'show_option_all' => 'Todas las categorías', 'hide_empty' => false, 'hierarchical' => true ) );
        echo ' <button class="button">Buscar</button></form>';
        echo '<table class="widefat striped"><thead><tr><th>ID</th><th>Pregunta</th><th>Destino</th><th>Categoría</th><th>Activa</th><th>Aperturas</th><th>Acciones</th></tr></thead><tbody>';
        foreach ( (array) $rows as $row ) {
            echo '<tr><td>' . absint( $row['id'] ) . '</td><td>' . esc_html( $row['question'] ) . '</td><td>' . absint( $row['object_type'] ) . ':' . absint( $row['object_id'] ) . '</td><td>' . esc_html( self::category_name( absint( $row['product_cat_id'] ?? 0 ) ) ) . '</td><td>' . ( $row['active'] ? 'Sí' : 'No' ) . '</td><td>' . absint( $row['open_count'] ) . '</td><td>';
            echo '<a class="button button-small" href="' . esc_url( self::url( array( 'faq_id' => absint( $row['id'] ) ) ) ) . '">Editar</a> ';
            echo '<form style="display:inline" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" onsubmit="return confirm(\'¿Eliminar esta FAQ definitivamente?\')"><input type="hidden" name="action" value="taxocommerce_faqs_delete"><input type="hidden" name="faq_id" value="' . absint( $row['id'] ) . '">';
            wp_nonce_field( 'taxocommerce_faqs_delete' );
            echo '<button class="button button-small">Eliminar</button></form></td></tr>';
        }
        echo '</tbody></table>';
        if ( count( (array) $rows ) === 25 || $page > 1 ) {
            echo '<p><a class="button" href="' . esc_url( self::url( array( 'faq_paged' => max( 1, $page - 1 ), 'faq_search' => $search ) ) ) . '">Anterior</a> <a class="button" href="' . esc_url( self::url( array( 'faq_paged' => $page + 1, 'faq_search' => $search ) ) ) . '">Siguiente</a></p>';
        }
        echo '<hr><h2>Importar / Exportar CSV</h2><p>CSV UTF-8; hasta 500 filas y 2 MB por importación. Solo se actualiza un ID existente; las nuevas filas reciben ID nuevo.</p>';
        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="taxocommerce_faqs_export">';
        wp_nonce_field( 'taxocommerce_faqs_export' ); submit_button( 'Exportar FAQs CSV', 'secondary', 'submit', false ); echo '</form><br>';
        echo '<form method="post" enctype="multipart/form-data" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="taxocommerce_faqs_import">';
        wp_nonce_field( 'taxocommerce_faqs_import' );
        echo '<input type="file" accept=".csv,text/csv" name="faqs_csv" required> ';
        submit_button( 'Importar CSV', 'primary', 'submit', false ); echo '</form></div>';
    }
}
