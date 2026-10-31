<?php

defined( 'ABSPATH' ) || exit;

final class TCV_Admin {
    public static function boot() {
        add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
        add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
        add_action( 'admin_post_tcv_analyze_product', array( __CLASS__, 'analyze_product' ) );
        add_action( 'admin_post_tcv_analyze_filtered', array( __CLASS__, 'analyze_filtered' ) );
        add_action( 'admin_post_tcv_export_classifier_json', array( __CLASS__, 'export_classifier_json' ) );
        add_action( 'admin_post_tcv_analyze_category', array( __CLASS__, 'analyze_category' ) );
        add_action( 'admin_post_tcv_create_term', array( __CLASS__, 'create_term' ) );
    }

    public static function menu() {
        if ( function_exists( 'taxocommerce_core' ) || defined( 'TAXOCOMMERCE_CORE_VERSION' ) ) {
            add_submenu_page(
                'taxocommerce-core',
                'TaxoCommerce Vocabulary',
                'Vocabulary',
                'manage_options',
                'taxocommerce-vocabulary',
                array( __CLASS__, 'render' )
            );
            return;
        }

        add_menu_page(
            'TaxoCommerce Vocabulary',
            'Vocabulary',
            'manage_options',
            'taxocommerce-vocabulary',
            array( __CLASS__, 'render' ),
            'dashicons-tag',
            56
        );
    }

    public static function assets( $hook ) {
        if ( false === strpos( $hook, 'taxocommerce-vocabulary' ) ) {
            return;
        }
        wp_enqueue_style( 'tcv-admin', TAXOCOMMERCE_VOCABULARY_URL . 'assets/admin.css', array(), TAXOCOMMERCE_VOCABULARY_VERSION );
    }

    private static function tab() {
        // GET only selects the view; mutations use separate POST actions with nonces.
        // phpcs:disable WordPress.Security.NonceVerification.Recommended
        $tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'labels';
        // phpcs:enable WordPress.Security.NonceVerification.Recommended
        return in_array( $tab, array( 'labels','attributes','classifier','io','control','auto-test','manual' ), true ) ? $tab : 'labels';
    }

    public static function render() {
        if ( ! current_user_can( 'manage_woocommerce' ) && ! current_user_can( 'manage_options' ) ) {
            return;
        }
        $tab = self::tab();
        echo '<div class="wrap tcv-wrap"><h1>TaxoCommerce Vocabulary <small>v' . esc_html( TAXOCOMMERCE_VOCABULARY_VERSION ) . '</small></h1><p class="description">Vocabulario canónico y atributos técnicos compartidos. Clasificador propone; Vocabulary conserva el dato canónico.</p>';
        // These GET parameters display an escaped notice; no state changes.
        // phpcs:disable WordPress.Security.NonceVerification.Recommended
        if ( ! empty( $_GET['tcv_message'] ) ) {
            $class = ( isset( $_GET['tcv_status'] ) && 'success' === $_GET['tcv_status'] ) ? 'notice-success' : 'notice-error';
            echo '<div class="notice ' . esc_attr( $class ) . '"><p>' . esc_html( rawurldecode( sanitize_text_field( wp_unslash( $_GET['tcv_message'] ) ) ) ) . '</p></div>';
        }
        // phpcs:enable WordPress.Security.NonceVerification.Recommended
        self::nav( $tab );
        if ( 'labels' === $tab ) {
            self::labels();
        } elseif ( 'attributes' === $tab ) {
            self::attributes();
        } elseif ( 'classifier' === $tab ) {
            self::classifier();
        } elseif ( 'io' === $tab ) {
            self::io();
        } elseif ( 'control' === $tab ) {
            self::control();
        } elseif ( 'auto-test' === $tab ) {
            TCV_Auto_Test::render();
        } else {
            TCV_Manual::render();
        }
        echo '</div>';
    }

    private static function nav( $active ) {
        $tabs = array( 'labels'=>'Etiquetas','attributes'=>'Atributos','classifier'=>'Clasificador','io'=>'Importar / Exportar','control'=>'Control','auto-test'=>'Auto Test','manual'=>'Manual' );
        echo '<nav class="nav-tab-wrapper">';
        foreach ( $tabs as $key => $label ) {
            $url = add_query_arg( array( 'page'=>'taxocommerce-vocabulary','tab'=>$key ), admin_url( 'admin.php' ) );
            echo '<a class="nav-tab ' . ( $active === $key ? 'nav-tab-active' : '' ) . '" href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a>';
        }
        echo '</nav>';
    }

    // Administrator displays and integrity checks read shared canonical tables live; other modules can change them independently.
    // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    private static function labels() {
        global $wpdb;
        $t = TCV_Schema::tables();
        $counts = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT semantic_group, COUNT(*) n FROM %i WHERE active=1 GROUP BY semantic_group',
                $t['vocabulary']
            ),
            OBJECT_K
        );
        echo '<div class="tcv-kpis">';
        foreach ( TCV_Classifier::GROUPS as $g ) {
            echo '<div class="tcv-card"><strong>' . esc_html( strtoupper( $g ) ) . '</strong><span>' . esc_html( number_format_i18n( isset( $counts[$g] ) ? $counts[$g]->n : 0 ) ) . '</span></div>';
        }
        echo '</div>';
        echo '<div class="tcv-panel"><h2>Alta de etiqueta</h2><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="tcv_create_term">';
        wp_nonce_field( 'tcv_create_term' );
        echo '<select name="group">';
        foreach ( TCV_Classifier::GROUPS as $g ) {
            echo '<option value="' . esc_attr( $g ) . '">' . esc_html( strtoupper( $g ) ) . '</option>';
        }
        echo '</select> <input name="label" class="regular-text" placeholder="Nombre visible" required> <button class="button button-primary">Dar de alta</button></form></div>';
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT semantic_group,slug,label,source,active FROM %i ORDER BY semantic_group,label LIMIT %d',
                $t['vocabulary'],
                300
            ),
            ARRAY_A
        );
        echo '<div class="tcv-panel"><h2>Diccionario</h2><table class="widefat striped"><thead><tr><th>Grupo</th><th>Etiqueta</th><th>Slug</th><th>Origen</th><th>Estado</th></tr></thead><tbody>';
        foreach ( $rows as $r ) {
            echo '<tr><td>' . esc_html( strtoupper( $r['semantic_group'] ) ) . '</td><td>' . esc_html( $r['label'] ) . '</td><td><code>' . esc_html( $r['slug'] ) . '</code></td><td>' . esc_html( $r['source'] ) . '</td><td>' . ( $r['active'] ? 'Activa' : 'Inactiva' ) . '</td></tr>';
        }
        echo '</tbody></table><p class="description">Se muestran como máximo 300 filas en esta vista.</p></div>';
    }

    // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    // Administrator displays and integrity checks read shared canonical tables live; other modules can change them independently.
    // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    private static function attributes() {
        global $wpdb;
        $t = TCV_Schema::tables();
        $attrs = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT a.*,
                    (SELECT COUNT(*) FROM %i t WHERE t.atributo_id=a.id AND t.activo=1) term_count,
                    (SELECT COUNT(*) FROM %i x WHERE x.atributo_id=a.id) alias_count
                 FROM %i a ORDER BY a.orden,a.nombre',
                $t['terms'],
                $t['aliases'],
                $t['attributes']
            ),
            ARRAY_A
        );
        echo '<div class="tcv-panel"><h2>Atributos técnicos canónicos</h2><p>Definiciones propias de TaxoCommerce. No se convierten automáticamente en atributos WooCommerce.</p><table class="widefat striped"><thead><tr><th>Atributo</th><th>Tipo</th><th>Unidad</th><th>Términos</th><th>Aliases</th><th>Estado</th></tr></thead><tbody>';
        foreach ( $attrs as $a ) {
            echo '<tr><td><strong>' . esc_html( $a['nombre'] ) . '</strong><br><code>' . esc_html( $a['slug'] ) . '</code></td><td>' . esc_html( $a['tipo'] ) . '</td><td>' . esc_html( $a['unidad_base'] ) . '</td><td>' . esc_html( $a['term_count'] ) . '</td><td>' . esc_html( $a['alias_count'] ) . '</td><td>' . ( $a['activo'] ? 'Activo' : 'Inactivo' ) . '</td></tr>';
        }
        echo '</tbody></table></div>';
    }

    // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    private static function classifier() {
        // GET controls only which results are displayed; POST analysis is nonce-protected.
        // phpcs:disable WordPress.Security.NonceVerification.Recommended
        $filters = TCV_Classifier::sanitize_filters(
            array(
                'search'      => isset( $_GET['search'] ) ? sanitize_text_field( wp_unslash( $_GET['search'] ) ) : '',
                'category_id' => isset( $_GET['category_id'] ) ? absint( wp_unslash( $_GET['category_id'] ) ) : 0,
                'coverage'    => isset( $_GET['coverage'] ) ? sanitize_key( wp_unslash( $_GET['coverage'] ) ) : 'missing_any',
                'priority'    => isset( $_GET['priority'] ) ? sanitize_key( wp_unslash( $_GET['priority'] ) ) : 'all',
                'rows'        => isset( $_GET['rows'] ) ? absint( wp_unslash( $_GET['rows'] ) ) : 25,
            )
        );
        // phpcs:enable WordPress.Security.NonceVerification.Recommended
        $categories = get_terms( array( 'taxonomy'=>'product_cat', 'hide_empty'=>false, 'number'=>250, 'orderby'=>'name', 'order'=>'ASC' ) );
        if ( is_wp_error( $categories ) ) {
            $categories = array();
        }

        echo '<div class="tcv-panel"><h2>Clasificador</h2><p>El análisis es correctivo y limitado. No recorre todo el catálogo: primero filtra y después analiza como máximo el número de filas seleccionado.</p>';
        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="tcv-filter">';
        echo '<input type="hidden" name="action" value="tcv_analyze_filtered">';
        wp_nonce_field( 'tcv_analyze_filtered' );
        echo '<label>Buscar<input type="text" name="search" value="' . esc_attr( $filters['search'] ) . '" placeholder="Producto, SKU o ID"></label>';
        echo '<label>Categoría<select name="category_id"><option value="0">Todas</option>';
        foreach ( $categories as $category ) {
            echo '<option value="' . esc_attr( $category->term_id ) . '" ' . selected( $filters['category_id'], $category->term_id, false ) . '>' . esc_html( $category->name ) . '</option>';
        }
        echo '</select></label>';
        $coverage_labels = array(
            'missing_any'=>'Cobertura incompleta (falta alguna)',
            'without_any'=>'Sin etiquetas semánticas',
            'without_type'=>'Sin TIPO',
            'without_role'=>'Sin ROL',
            'without_application'=>'Sin APLICACIÓN',
            'without_platform'=>'Sin PLATAFORMA',
            'without_subtype'=>'Sin SUBTIPO',
        );
        echo '<label>Cobertura<select name="coverage">';
        foreach ( $coverage_labels as $value=>$label ) {
            echo '<option value="' . esc_attr( $value ) . '" ' . selected( $filters['coverage'], $value, false ) . '>' . esc_html( $label ) . '</option>';
        }
        echo '</select></label>';
        $priority_labels = array( 'all'=>'Todas las anomalías','p1'=>'P1 · falta TIPO','p2'=>'P2 · falta ROL','p3'=>'P3 · falta APLICACIÓN','p4'=>'P4 · falta SUBTIPO','p5'=>'P5 · falta PLATAFORMA' );
        echo '<label>Prioridad<select name="priority">';
        foreach ( $priority_labels as $value=>$label ) {
            echo '<option value="' . esc_attr( $value ) . '" ' . selected( $filters['priority'], $value, false ) . '>' . esc_html( $label ) . '</option>';
        }
        echo '</select></label>';
        echo '<label>Filas<select name="rows">';
        foreach ( array(10,25,50,100) as $n ) {
            echo '<option value="' . esc_attr( $n ) . '" ' . selected( $filters['rows'], $n, false ) . '>' . esc_html( $n ) . '</option>';
        }
        echo '</select></label>';
        echo '<div class="tcv-filter-actions"><button class="button button-primary">Analizar filtro</button></div>';
        echo '</form></div>';

        $report = TCV_Classifier::last_report();
        if ( $report ) {
            $export = wp_nonce_url( admin_url( 'admin-post.php?action=tcv_export_classifier_json' ), 'tcv_export_classifier_json' );
            echo '<div class="tcv-panel"><h2>Último análisis filtrado</h2><p>Procesados: <strong>' . esc_html( absint( $report['processed'] ?? 0 ) ) . '</strong> · Generado: ' . esc_html( (string) ( $report['generated_gmt'] ?? '' ) ) . ' UTC.</p>';
            echo '<p><a class="button" href="' . esc_url( $export ) . '">Exportar JSON del análisis</a></p>';
            echo '<pre class="tcv-json">' . esc_html( wp_json_encode( (array) ( $report['filters'] ?? array() ), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) ) . '</pre></div>';
        }

        $rows = TCV_Classifier::recent_proposals( 100 );
        echo '<div class="tcv-panel"><h2>Propuestas recientes</h2><table class="widefat striped"><thead><tr><th>Producto</th><th>Grupo</th><th>Propuesta</th><th>Estado</th><th>Confianza</th><th>Actualizado</th></tr></thead><tbody>';
        foreach ( $rows as $r ) {
            $edit = get_edit_post_link( absint( $r['product_id'] ) );
            echo '<tr><td><a href="' . esc_url( $edit ) . '">#' . esc_html( $r['product_id'] ) . '</a></td><td>' . esc_html( $r['semantic_group'] ) . '</td><td>' . esc_html( $r['label'] ) . '</td><td>' . esc_html( $r['state'] ) . '</td><td>' . esc_html( number_format_i18n( (float) $r['confidence'] * 100, 1 ) ) . '%</td><td>' . esc_html( $r['updated_at'] ) . '</td></tr>';
        }
        echo '</tbody></table></div>';

        echo '<div class="tcv-panel"><h2>Análisis puntual de categoría</h2><p>Para revisar una categoría concreta sin recorrer el catálogo completo.</p><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="tcv_analyze_category">';
        wp_nonce_field( 'tcv_analyze_category' );
        echo '<label>Categoría ID <input type="number" name="term_id" min="1" required></label> <button class="button">Analizar categoría</button></form></div>';

        $cats = TCV_Classifier::recent_category_proposals( 100 );
        echo '<div class="tcv-panel"><h2>Propuestas recientes de categorías</h2><table class="widefat striped"><thead><tr><th>Categoría</th><th>Grupo</th><th>Propuesta</th><th>Estado</th><th>Confianza</th></tr></thead><tbody>';
        foreach ( $cats as $r ) {
            $term = get_term( absint( $r['term_id'] ), 'product_cat' );
            $name = ( $term && ! is_wp_error( $term ) ) ? $term->name : '#' . $r['term_id'];
            echo '<tr><td>' . esc_html( $name ) . '</td><td>' . esc_html( $r['semantic_group'] ) . '</td><td>' . esc_html( $r['label'] ) . '</td><td>' . esc_html( $r['state'] ) . '</td><td>' . esc_html( number_format_i18n( (float) $r['confidence'] * 100, 1 ) ) . '%</td></tr>';
        }
        echo '</tbody></table></div>';
    }

    private static function io() {
        $labels = wp_nonce_url( admin_url( 'admin-post.php?action=tcv_export_labels' ), 'tcv_export_labels' );
        $attrs = wp_nonce_url( admin_url( 'admin-post.php?action=tcv_export_attributes' ), 'tcv_export_attributes' );

        echo '<div class="tcv-grid">';
        echo '<div class="tcv-panel"><h2>Etiquetas</h2><p>Exporta/importa términos ROL, TIPO, APLICACIÓN, PLATAFORMA, SUBTIPO y la correspondencia TIPO → ROL.</p><a class="button button-primary" href="' . esc_url( $labels ) . '">Exportar CSV de etiquetas</a>';
        self::render_import_form( 'labels' );
        echo '</div>';

        echo '<div class="tcv-panel"><h2>Atributos</h2><p>Exporta/importa definiciones de atributos, términos controlados y aliases. No incluye valores asignados a productos.</p><a class="button button-primary" href="' . esc_url( $attrs ) . '">Exportar CSV de atributos</a>';
        self::render_import_form( 'attributes' );
        echo '</div></div>';

        TCV_Portable::render_panel();
    }

    private static function render_import_form( $domain ) {
        ?>
        <form class="tcv-import" method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
            <input type="hidden" name="action" value="tcv_import">
            <input type="hidden" name="domain" value="<?php echo esc_attr( $domain ); ?>">
            <?php wp_nonce_field( 'tcv_import' ); ?>
            <input type="file" name="csv" accept=".csv,text/csv" required>
            <button class="button">Importar CSV</button>
        </form>
        <?php
    }

    // Administrator displays and integrity checks read shared canonical tables live; other modules can change them independently.
    // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    private static function control() {
        global $wpdb;
        $t = TCV_Schema::tables();
        echo '<div class="tcv-panel"><h2>Control de integridad</h2><table class="widefat striped"><thead><tr><th>Tabla</th><th>Estado</th><th>Filas</th></tr></thead><tbody>';
        foreach ( $t as $table ) {
            $exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
            $count = 0;
            if ( $exists === $table ) {
                $count = (int) $wpdb->get_var(
                    $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $table )
                );
            }
            echo '<tr><td><code>' . esc_html( $table ) . '</code></td><td>' . ( $exists === $table ? 'OK' : 'Falta' ) . '</td><td>' . esc_html( number_format_i18n( $count ) ) . '</td></tr>';
        }
        echo '</tbody></table></div>';
    }

    // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    public static function analyze_filtered() {
        if ( ! current_user_can( 'manage_woocommerce' ) && ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Sin permisos.' );
        }
        check_admin_referer( 'tcv_analyze_filtered' );
        $filters = TCV_Classifier::sanitize_filters( array(
            'search'      => isset( $_POST['search'] ) ? sanitize_text_field( wp_unslash( $_POST['search'] ) ) : '',
            'category_id' => isset( $_POST['category_id'] ) ? absint( wp_unslash( $_POST['category_id'] ) ) : 0,
            'coverage'    => isset( $_POST['coverage'] ) ? sanitize_key( wp_unslash( $_POST['coverage'] ) ) : 'missing_any',
            'priority'    => isset( $_POST['priority'] ) ? sanitize_key( wp_unslash( $_POST['priority'] ) ) : 'all',
            'rows'        => isset( $_POST['rows'] ) ? absint( wp_unslash( $_POST['rows'] ) ) : 25,
        ) );
        $report = TCV_Classifier::analyze_filtered_products( $filters );
        $args = array_merge( array( 'page'=>'taxocommerce-vocabulary','tab'=>'classifier','tcv_status'=>'success','tcv_message'=>rawurlencode( sprintf( 'Análisis completado: %d productos procesados.', absint( $report['processed'] ?? 0 ) ) ) ), $filters );
        wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
        exit;
    }

    public static function export_classifier_json() {
        if ( ! current_user_can( 'manage_woocommerce' ) && ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Sin permisos.' );
        }
        check_admin_referer( 'tcv_export_classifier_json' );
        $report = TCV_Classifier::last_report();
        if ( ! $report ) {
            wp_die( 'No existe un análisis del Clasificador para exportar.', '', array( 'response'=>404 ) );
        }
        nocache_headers();
        header( 'X-Content-Type-Options: nosniff' );
        header( 'Content-Type: application/json; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename="taxocommerce_clasificador.json"' );
        echo wp_json_encode( $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE );
        exit;
    }

    public static function analyze_product() {
        if ( ! current_user_can( 'manage_woocommerce' ) && ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Sin permisos.' );
        }
        check_admin_referer( 'tcv_analyze_product' );
        $id = absint( $_POST['product_id'] ?? 0 );
        $r = TCV_Classifier::analyze_product( $id );
        $msg = is_wp_error( $r ) ? $r->get_error_message() : sprintf( 'Producto #%d analizado: %d etiquetas y %d atributos propuestos.', $id, count( $r['labels'] ), count( $r['attributes'] ) );
        wp_safe_redirect( add_query_arg( array( 'page'=>'taxocommerce-vocabulary','tab'=>'classifier','tcv_status'=>is_wp_error( $r )?'error':'success','tcv_message'=>rawurlencode( $msg ) ), admin_url( 'admin.php' ) ) );
        exit;
    }

    public static function analyze_category() {
        if ( ! current_user_can( 'manage_woocommerce' ) && ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Sin permisos.' );
        }
        check_admin_referer( 'tcv_analyze_category' );
        $id = absint( $_POST['term_id'] ?? 0 );
        $r = TCV_Classifier::analyze_category( $id );
        $msg = is_wp_error( $r ) ? $r->get_error_message() : sprintf( 'Categoría #%d analizada: %d etiquetas propuestas.', $id, count( $r['labels'] ) );
        wp_safe_redirect( add_query_arg( array( 'page'=>'taxocommerce-vocabulary','tab'=>'classifier','tcv_status'=>is_wp_error( $r )?'error':'success','tcv_message'=>rawurlencode( $msg ) ), admin_url( 'admin.php' ) ) );
        exit;
    }

    // Administrator displays and integrity checks read shared canonical tables live; other modules can change them independently.
    // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    public static function create_term() {
        if ( ! current_user_can( 'manage_woocommerce' ) && ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Sin permisos.' );
        }
        check_admin_referer( 'tcv_create_term' );
        global $wpdb;
        $t = TCV_Schema::tables();
        $g = sanitize_key( $_POST['group'] ?? '' );
        $label = sanitize_text_field( wp_unslash( $_POST['label'] ?? '' ) );
        if ( in_array( $g, TCV_Classifier::GROUPS, true ) && $label ) {
            $slug = sanitize_title( $label );
            $wpdb->query( $wpdb->prepare( "INSERT INTO %i (semantic_group,slug,label,source,active) VALUES (%s,%s,%s,'manual',1) ON DUPLICATE KEY UPDATE label=VALUES(label),active=1,source='manual'", $t['vocabulary'], $g, $slug, $label ) );
        }
        wp_safe_redirect( add_query_arg( array( 'page'=>'taxocommerce-vocabulary','tab'=>'labels' ), admin_url( 'admin.php' ) ) );
        exit;
    }

    // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching}
