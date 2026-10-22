<?php

defined( 'ABSPATH' ) || exit;

final class TCV_Admin {
    public static function boot() {
        add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
        add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
        add_action( 'admin_post_tcv_analyze_product', array( __CLASS__, 'analyze_product' ) );
        add_action( 'admin_post_tcv_analyze_category', array( __CLASS__, 'analyze_category' ) );
        add_action( 'admin_post_tcv_create_term', array( __CLASS__, 'create_term' ) );
    }

    public static function menu() {
        add_menu_page( 'TaxoCommerce Vocabulary', 'Vocabulary', 'manage_woocommerce', 'taxocommerce-vocabulary', array( __CLASS__, 'render' ), 'dashicons-tag', 56 );
    }

    public static function assets( $hook ) {
        if ( false === strpos( $hook, 'taxocommerce-vocabulary' ) ) {
            return;
        }
        wp_enqueue_style( 'tcv-admin', TAXOCOMMERCE_VOCABULARY_URL . 'assets/admin.css', array(), TAXOCOMMERCE_VOCABULARY_VERSION );
    }

    private static function tab() {
        $tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'labels';
        return in_array( $tab, array( 'labels','attributes','classifier','io','control' ), true ) ? $tab : 'labels';
    }

    public static function render() {
        if ( ! current_user_can( 'manage_woocommerce' ) && ! current_user_can( 'manage_options' ) ) {
            return;
        }
        $tab = self::tab();
        echo '<div class="wrap tcv-wrap"><h1>TaxoCommerce Vocabulary</h1><p class="description">Vocabulario canónico y atributos técnicos compartidos. Clasificador propone; Vocabulary conserva el dato canónico.</p>';
        if ( ! empty( $_GET['tcv_message'] ) ) {
            $class = ( isset( $_GET['tcv_status'] ) && 'success' === $_GET['tcv_status'] ) ? 'notice-success' : 'notice-error';
            echo '<div class="notice ' . esc_attr( $class ) . '"><p>' . esc_html( rawurldecode( sanitize_text_field( wp_unslash( $_GET['tcv_message'] ) ) ) ) . '</p></div>';
        }
        self::nav( $tab );
        if ( 'labels' === $tab ) {
            self::labels();
        } elseif ( 'attributes' === $tab ) {
            self::attributes();
        } elseif ( 'classifier' === $tab ) {
            self::classifier();
        } elseif ( 'io' === $tab ) {
            self::io();
        } else {
            self::control();
        }
        echo '</div>';
    }

    private static function nav( $active ) {
        $tabs = array( 'labels'=>'Etiquetas','attributes'=>'Atributos','classifier'=>'Clasificador','io'=>'Importar / Exportar','control'=>'Control' );
        echo '<nav class="nav-tab-wrapper">';
        foreach ( $tabs as $key => $label ) {
            $url = add_query_arg( array( 'page'=>'taxocommerce-vocabulary','tab'=>$key ), admin_url( 'admin.php' ) );
            echo '<a class="nav-tab ' . ( $active === $key ? 'nav-tab-active' : '' ) . '" href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a>';
        }
        echo '</nav>';
    }

    private static function labels() {
        global $wpdb;
        $t = TCV_Schema::tables();
        $counts = $wpdb->get_results( "SELECT semantic_group,COUNT(*) n FROM {$t['vocabulary']} WHERE active=1 GROUP BY semantic_group", OBJECT_K );
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
        $rows = $wpdb->get_results( "SELECT semantic_group,slug,label,source,active FROM {$t['vocabulary']} ORDER BY semantic_group,label LIMIT 300", ARRAY_A );
        echo '<div class="tcv-panel"><h2>Diccionario</h2><table class="widefat striped"><thead><tr><th>Grupo</th><th>Etiqueta</th><th>Slug</th><th>Origen</th><th>Estado</th></tr></thead><tbody>';
        foreach ( $rows as $r ) {
            echo '<tr><td>' . esc_html( strtoupper( $r['semantic_group'] ) ) . '</td><td>' . esc_html( $r['label'] ) . '</td><td><code>' . esc_html( $r['slug'] ) . '</code></td><td>' . esc_html( $r['source'] ) . '</td><td>' . ( $r['active'] ? 'Activa' : 'Inactiva' ) . '</td></tr>';
        }
        echo '</tbody></table><p class="description">Se muestran como máximo 300 filas en esta vista.</p></div>';
    }

    private static function attributes() {
        global $wpdb;
        $t = TCV_Schema::tables();
        $attrs = $wpdb->get_results( "SELECT a.*, (SELECT COUNT(*) FROM {$t['terms']} t WHERE t.atributo_id=a.id AND t.activo=1) term_count, (SELECT COUNT(*) FROM {$t['aliases']} x WHERE x.atributo_id=a.id) alias_count FROM {$t['attributes']} a ORDER BY a.orden,a.nombre", ARRAY_A );
        echo '<div class="tcv-panel"><h2>Atributos técnicos canónicos</h2><p>Definiciones propias de TaxoCommerce. No se convierten automáticamente en atributos WooCommerce.</p><table class="widefat striped"><thead><tr><th>Atributo</th><th>Tipo</th><th>Unidad</th><th>Términos</th><th>Aliases</th><th>Estado</th></tr></thead><tbody>';
        foreach ( $attrs as $a ) {
            echo '<tr><td><strong>' . esc_html( $a['nombre'] ) . '</strong><br><code>' . esc_html( $a['slug'] ) . '</code></td><td>' . esc_html( $a['tipo'] ) . '</td><td>' . esc_html( $a['unidad_base'] ) . '</td><td>' . esc_html( $a['term_count'] ) . '</td><td>' . esc_html( $a['alias_count'] ) . '</td><td>' . ( $a['activo'] ? 'Activo' : 'Inactivo' ) . '</td></tr>';
        }
        echo '</tbody></table></div>';
    }

    private static function classifier() {
        echo '<div class="tcv-grid"><div class="tcv-panel"><h2>Clasificar producto</h2><p>Analiza título, extracto, contenido y categorías. Propone semántica y atributos sin aplicar cambios.</p><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="tcv_analyze_product">';
        wp_nonce_field( 'tcv_analyze_product' );
        echo '<label>Producto ID <input type="number" name="product_id" min="1" required></label> <button class="button button-primary">Analizar producto</button></form></div>';
        echo '<div class="tcv-panel"><h2>Clasificar categoría</h2><p>Analiza nombre, descripción y una muestra de hasta 50 productos publicados de la categoría.</p><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="tcv_analyze_category">';
        wp_nonce_field( 'tcv_analyze_category' );
        echo '<label>Categoría ID <input type="number" name="term_id" min="1" required></label> <button class="button button-primary">Analizar categoría</button></form></div></div>';

        $rows = TCV_Classifier::recent_proposals( 100 );
        echo '<div class="tcv-panel"><h2>Propuestas recientes</h2><table class="widefat striped"><thead><tr><th>Producto</th><th>Grupo</th><th>Propuesta</th><th>Estado</th><th>Confianza</th><th>Actualizado</th></tr></thead><tbody>';
        foreach ( $rows as $r ) {
            $edit = get_edit_post_link( absint( $r['product_id'] ) );
            echo '<tr><td><a href="' . esc_url( $edit ) . '">#' . esc_html( $r['product_id'] ) . '</a></td><td>' . esc_html( $r['semantic_group'] ) . '</td><td>' . esc_html( $r['label'] ) . '</td><td>' . esc_html( $r['state'] ) . '</td><td>' . esc_html( number_format_i18n( (float) $r['confidence'] * 100, 1 ) ) . '%</td><td>' . esc_html( $r['updated_at'] ) . '</td></tr>';
        }
        echo '</tbody></table></div>';

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
        echo '<div class="tcv-grid"><div class="tcv-panel"><h2>Etiquetas</h2><p>Exporta/importa términos ROL, TIPO, APLICACIÓN, PLATAFORMA, SUBTIPO y la correspondencia TIPO → ROL.</p><a class="button button-primary" href="' . esc_url( $labels ) . '">Exportar CSV de etiquetas</a>' . self::import_form( 'labels' ) . '</div>';
        echo '<div class="tcv-panel"><h2>Atributos</h2><p>Exporta/importa definiciones de atributos, términos controlados y aliases. No incluye valores asignados a productos.</p><a class="button button-primary" href="' . esc_url( $attrs ) . '">Exportar CSV de atributos</a>' . self::import_form( 'attributes' ) . '</div></div>';
    }

    private static function import_form( $domain ) {
        ob_start();
        ?>
        <form class="tcv-import" method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
            <input type="hidden" name="action" value="tcv_import">
            <input type="hidden" name="domain" value="<?php echo esc_attr( $domain ); ?>">
            <?php wp_nonce_field( 'tcv_import' ); ?>
            <input type="file" name="csv" accept=".csv,text/csv" required>
            <button class="button">Importar CSV</button>
        </form>
        <?php
        return ob_get_clean();
    }

    private static function control() {
        global $wpdb;
        $t = TCV_Schema::tables();
        echo '<div class="tcv-panel"><h2>Control de integridad</h2><table class="widefat striped"><thead><tr><th>Tabla</th><th>Estado</th><th>Filas</th></tr></thead><tbody>';
        foreach ( $t as $table ) {
            $exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
            $count = 0;
            if ( $exists === $table ) {
                $count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
            }
            echo '<tr><td><code>' . esc_html( $table ) . '</code></td><td>' . ( $exists === $table ? 'OK' : 'Falta' ) . '</td><td>' . esc_html( number_format_i18n( $count ) ) . '</td></tr>';
        }
        echo '</tbody></table></div>';
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
            $wpdb->query( $wpdb->prepare( "INSERT INTO {$t['vocabulary']} (semantic_group,slug,label,source,active) VALUES (%s,%s,%s,'manual',1) ON DUPLICATE KEY UPDATE label=VALUES(label),active=1,source='manual'", $g, $slug, $label ) );
        }
        wp_safe_redirect( add_query_arg( array( 'page'=>'taxocommerce-vocabulary','tab'=>'labels' ), admin_url( 'admin.php' ) ) );
        exit;
    }
}
