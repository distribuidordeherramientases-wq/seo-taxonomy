<?php
/**
 * On-demand, read-only functional smoke checks for TaxoCommerce Core.
 * Only a user-specific report transient is written; site content is untouched.
 *
 * @package TaxoCommerceCore
 */
defined( 'ABSPATH' ) || exit;

final class TaxoCommerce_Core_Auto_Test {
    const PAGE_SLUG = 'taxocommerce-core-auto-test';
    const ACTION = 'taxocommerce_core_run_auto_test';
    const NONCE = 'taxocommerce_core_auto_test_run';
    const REPORT_PREFIX = 'taxocommerce_core_autotest_';

    public static function register_hooks() {
        add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'handle_run' ) );
    }

    private static function allowed() {
        return current_user_can( apply_filters( 'taxocommerce_core_manage_capability', 'manage_options' ) );
    }

    public static function handle_run() {
        if ( ! self::allowed() ) {
            wp_die( esc_html__( 'Sin permisos para ejecutar Auto Test.', 'taxocommerce-core' ) );
        }
        check_admin_referer( self::NONCE, 'tc_autotest_nonce' );
        set_transient( self::REPORT_PREFIX . get_current_user_id(), self::run_suite(), DAY_IN_SECONDS );
        wp_safe_redirect( add_query_arg( array( 'page' => 'taxocommerce-core', 'tab' => 'auto-test' ), admin_url( 'admin.php' ) ) );
        exit;
    }

    /** List of observable behaviours, not just installed class names. */
    public static function cases() {
        return array(
            'runtime'      => array( 'Core', 'Arranque y servicios' ),
            'actions'      => array( 'Core', 'Acciones administrativas registradas' ),
            'content'      => array( 'Contenidos', 'Consulta de páginas y entradas' ),
            'catalog'      => array( 'Contenidos', 'Consulta de productos y categorías' ),
            'inventory'    => array( 'Contenidos', 'Inventario de contenidos' ),
            'countries'    => array( 'Países', 'Códigos de país disponibles' ),
            'post_country' => array( 'Países', 'País de páginas internacionales' ),
            'term_country' => array( 'Países', 'País de categorías internacionales' ),
            'nodes'        => array( 'SEO Nodes', 'Tabla de roles SEO' ),
            'role_read'    => array( 'SEO Nodes', 'Lectura de un rol guardado' ),
            'relations'    => array( 'SEO Relations', 'Tabla y lectura de relaciones' ),
            'edges'        => array( 'SEO Relations', 'Integridad de extremos y países (muestra)' ),
            'menus'        => array( 'Menús', 'Menús separados por país y lectura de sus elementos' ),
            'extensions'   => array( 'Extensiones', 'Registro de módulos' ),
            'vocabulary'   => array( 'Editor', 'Tipos y vocabulario semántico' ),
            'report'       => array( 'Informes', 'Cálculo del resumen de arquitectura' ),
            'write_uat'    => array( 'Operaciones de escritura', 'Guardado, edición y sincronización reales' ),
        );
    }

    private static function outcome( $status, $message ) {
        return array( 'status' => $status, 'message' => (string) $message );
    }
    private static function ok( $message ) { return self::outcome( 'pass', $message ); }
    private static function bad( $message ) { return self::outcome( 'fail', $message ); }
    private static function skip( $message ) { return self::outcome( 'skip', $message ); }

    /** Keeps the rest of the suite running if a check throws. */
    public static function run_suite( $cases = null ) {
        $cases = null === $cases ? self::cases() : $cases;
        $start = microtime( true );
        $rows = array();
        $totals = array( 'pass' => 0, 'fail' => 0, 'skip' => 0 );
        foreach ( $cases as $id => $labels ) {
            $began = microtime( true );
            try {
                $method = array( __CLASS__, 'check_' . $id );
                $result = is_callable( $method ) ? call_user_func( $method ) : self::bad( 'Test sin implementación: ' . $id );
                if ( ! is_array( $result ) || ! isset( $result['status'], $result['message'] ) ||
                     ! in_array( $result['status'], array( 'pass', 'fail', 'skip' ), true ) ) {
                    $result = self::bad( 'Resultado inválido del test.' );
                }
            } catch ( Throwable $error ) {
                $result = self::bad( get_class( $error ) . ': ' . $error->getMessage() );
            }
            $result['id'] = $id;
            $result['group'] = $labels[0];
            $result['label'] = $labels[1];
            $result['ms'] = (int) round( ( microtime( true ) - $began ) * 1000 );
            $rows[] = $result;
            $totals[ $result['status'] ]++;
        }
        return array(
            'version' => defined( 'TAXOCOMMERCE_CORE_VERSION' ) ? TAXOCOMMERCE_CORE_VERSION : '',
            'date' => current_time( 'mysql' ),
            'ms' => (int) round( ( microtime( true ) - $start ) * 1000 ),
            'totals' => $totals,
            'rows' => $rows,
        );
    }

    private static function table( $suffix ) {
        global $wpdb;
        return $wpdb->prefix . $suffix;
    }

    private static function exists( $table ) {
        global $wpdb;
        return $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
    }

    private static function missing( $table, $columns ) {
        global $wpdb;
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Trusted WordPress prefix and fixed Core suffix.
        $available = $wpdb->get_col( 'SHOW COLUMNS FROM ' . esc_sql( $table ) );
        return is_array( $available ) ? array_diff( $columns, $available ) : $columns;
    }

    private static function check_runtime() {
        if ( ! defined( 'TAXOCOMMERCE_CORE_VERSION' ) || ! function_exists( 'taxocommerce_core' ) ) {
            return self::bad( 'Core no cargó su punto de entrada.' );
        }
        $core = taxocommerce_core();
        if ( ! is_object( $core ) || ! is_callable( array( $core, 'content' ) ) ||
            ! is_callable( array( $core, 'hierarchy' ) ) || ! is_callable( array( $core, 'menu' ) ) ) {
            return self::bad( 'Faltan los servicios de contenido, jerarquía o menús.' );
        }
        return self::ok( 'Core ' . TAXOCOMMERCE_CORE_VERSION . '; servicios accesibles.' );
    }

    private static function check_actions() {
        $names = array(
            'taxocommerce_core_save_content', 'taxocommerce_core_save_page_role',
            'taxocommerce_core_replace_hierarchy_relations', 'taxocommerce_core_sync_menu',
            'taxocommerce_core_activate_menu', 'taxocommerce_core_upload_extension', self::ACTION,
        );
        foreach ( $names as $name ) {
            if ( false === has_action( 'admin_post_' . $name ) ) {
                return self::bad( 'Acción no registrada: admin_post_' . $name );
            }
        }
        return self::ok( count( $names ) . ' acciones conectadas. No se ejecutan sus escrituras.' );
    }

    private static function check_content() {
        if ( ! post_type_exists( 'page' ) || ! post_type_exists( 'post' ) ) {
            return self::bad( 'WordPress no registra page o post.' );
        }
        $pages = wp_count_posts( 'page' );
        $posts = wp_count_posts( 'post' );
        if ( ! is_object( $pages ) || ! is_object( $posts ) ) {
            return self::bad( 'No se pudo leer wp_count_posts().' );
        }
        return self::ok( 'Páginas publicadas: ' . absint( $pages->publish ?? 0 ) .
            '; entradas publicadas: ' . absint( $posts->publish ?? 0 ) . '.' );
    }

    private static function check_catalog() {
        if ( ! post_type_exists( 'product' ) || ! taxonomy_exists( 'product_cat' ) ) {
            return self::skip( 'WooCommerce no está disponible en esta instalación.' );
        }
        $products = wp_count_posts( 'product' );
        $cats = wp_count_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => false ) );
        if ( ! is_object( $products ) || is_wp_error( $cats ) ) {
            return self::bad( 'Fallo en el recuento de productos o categorías.' );
        }
        return self::ok( 'Productos publicados: ' . absint( $products->publish ?? 0 ) .
            '; categorías: ' . absint( $cats ) . '.' );
    }

    private static function check_inventory() {
        $data = TaxoCommerce_Core_Content_Inventory::snapshot();
        if ( ! is_array( $data ) || ! isset( $data['types']['pages'], $data['types']['posts'], $data['generated_at'] ) ) {
            return self::bad( 'El inventario no devuelve páginas, entradas y fecha.' );
        }
        return self::ok( 'Inventario generado correctamente; timestamp ' . absint( $data['generated_at'] ) . '.' );
    }

    private static function check_countries() {
        foreach ( array( 'ES', 'US', 'FR', 'DE', 'NL' ) as $code ) {
            if ( TaxoCommerce_Core_Country::normalize( strtolower( $code ) ) !== $code ) {
                return self::bad( 'Normalización incorrecta: ' . $code );
            }
        }
        return '' === TaxoCommerce_Core_Country::normalize( 'NOT-A-COUNTRY' )
            ? self::ok( 'ES, US, FR, DE y NL válidos; valores desconocidos rechazados.' )
            : self::bad( 'Se aceptan códigos de país desconocidos.' );
    }

    private static function check_post_country() {
        global $wpdb;
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT ID, post_name FROM {$wpdb->posts} WHERE post_type=%s AND post_status NOT IN ('trash','auto-draft')
             AND post_name LIKE %s ORDER BY ID DESC LIMIT 100",
            'page', $wpdb->esc_like( 'vevor-' ) . '%'
        ), ARRAY_A );
        if ( ! is_array( $rows ) ) {
            return self::bad( 'Error leyendo páginas: ' . $wpdb->last_error );
        }
        $checked = 0;
        foreach ( $rows as $row ) {
            if ( ! preg_match( '/^vevor-(us|fr|de|nl)(?:-|$)/', $row['post_name'], $m ) ) {
                continue;
            }
            $country = strtoupper( $m[1] );
            if ( $country !== TaxoCommerce_Core_Country::post( $row['ID'] ) ) {
                return self::bad( 'Página #' . absint( $row['ID'] ) . ': el país no coincide con el slug (' . $country . ').' );
            }
            $checked++;
        }
        return $checked ? self::ok( $checked . ' páginas validadas (muestra hasta 100).' ) :
            self::skip( 'No existen páginas internacionales en la muestra.' );
    }

    private static function check_term_country() {
        global $wpdb;
        if ( ! taxonomy_exists( 'product_cat' ) ) {
            return self::skip( 'No existe la taxonomía product_cat.' );
        }
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT t.term_id, t.slug FROM {$wpdb->terms} t
             JOIN {$wpdb->term_taxonomy} tt ON tt.term_id=t.term_id AND tt.taxonomy=%s
             WHERE t.slug LIKE %s ORDER BY t.term_id DESC LIMIT 100",
            'product_cat', $wpdb->esc_like( 'vevor-' ) . '%'
        ), ARRAY_A );
        if ( ! is_array( $rows ) ) {
            return self::bad( 'Error leyendo categorías: ' . $wpdb->last_error );
        }
        $checked = 0;
        foreach ( $rows as $row ) {
            if ( ! preg_match( '/^vevor-(us|fr|de|nl)(?:-|$)/', $row['slug'], $m ) ) {
                continue;
            }
            $country = strtoupper( $m[1] );
            if ( $country !== TaxoCommerce_Core_Country::term( $row['term_id'] ) ) {
                return self::bad( 'Categoría #' . absint( $row['term_id'] ) . ': país incoherente con ' . $country . '.' );
            }
            $checked++;
        }
        return $checked ? self::ok( $checked . ' categorías verificadas (muestra hasta 100).' ) :
            self::skip( 'No existen categorías internacionales en la muestra.' );
    }

    private static function check_nodes() {
        global $wpdb;
        $table = self::table( 'seo_nodes' );
        if ( ! self::exists( $table ) ) {
            return self::bad( 'No existe ' . $table . '.' );
        }
        $missing = self::missing( $table, array( 'id', 'object_type', 'object_id', 'seo_role', 'status' ) );
        if ( $missing ) {
            return self::bad( 'Columnas ausentes en seo_nodes: ' . implode( ', ', $missing ) );
        }
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Verified Core table, fixed predicate.
        $count = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE object_type='page' AND status=1", $table ) );
        return null === $count ? self::bad( 'Error leyendo nodos: ' . $wpdb->last_error ) :
            self::ok( absint( $count ) . ' nodos activos; columnas obligatorias disponibles.' );
    }

    private static function check_role_read() {
        global $wpdb;
        $table = self::table( 'seo_nodes' );
        if ( ! self::exists( $table ) ) {
            return self::bad( 'No existe seo_nodes.' );
        }
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Verified Core table, fixed predicate.
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT object_id,seo_role FROM %i WHERE object_type='page' AND status=1 ORDER BY id DESC LIMIT 1", $table ), ARRAY_A );
        if ( ! is_array( $row ) ) {
            return $wpdb->last_error ? self::bad( 'Error leyendo roles: ' . $wpdb->last_error ) :
                self::skip( 'No hay nodos activos para comprobar lectura.' );
        }
        $actual = taxocommerce_core()->hierarchy()->get_page_role( $row['object_id'] );
        return $actual === $row['seo_role'] ?
            self::ok( 'Página #' . absint( $row['object_id'] ) . ': rol "' . $actual . '" recuperado.' ) :
            self::bad( 'Página #' . absint( $row['object_id'] ) . ': rol guardado "' . $row['seo_role'] . '", recuperado "' . $actual . '".' );
    }

    private static function check_relations() {
        global $wpdb;
        $table = self::table( 'seo_relations' );
        if ( ! self::exists( $table ) ) {
            return self::bad( 'No existe ' . $table . '.' );
        }
        $missing = self::missing( $table, array( 'id', 'source_type', 'source_id', 'target_type', 'target_id', 'relation_type' ) );
        if ( $missing ) {
            return self::bad( 'Columnas ausentes en seo_relations: ' . implode( ', ', $missing ) );
        }
        if ( ! is_callable( array( 'TaxoCommerce_Core_Hierarchy_Classic', 'rows' ) ) ) {
            return self::bad( 'No está disponible el lector de jerarquía.' );
        }
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Verified Core table identifier.
        $count = $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $table ) );
        return null === $count ? self::bad( 'Error leyendo relaciones: ' . $wpdb->last_error ) :
            self::ok( absint( $count ) . ' relaciones en tabla; lector cargado.' );
    }

    private static function check_edges() {
        global $wpdb;
        $table = self::table( 'seo_relations' );
        if ( ! self::exists( $table ) ) {
            return self::bad( 'No existe seo_relations.' );
        }
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Fixed, bounded read of Core table.
        $rows = $wpdb->get_results( $wpdb->prepare( 'SELECT id,source_type,source_id,target_type,target_id,relation_type FROM %i ORDER BY id DESC LIMIT 60', $table ), ARRAY_A );
        if ( ! is_array( $rows ) ) {
            return self::bad( 'Error leyendo relaciones: ' . $wpdb->last_error );
        }
        if ( ! $rows ) {
            return self::skip( 'No hay relaciones para comprobar.' );
        }
        $def = TaxoCommerce_Core_Hierarchy_Classic::definitions();
        foreach ( $rows as $row ) {
            $id = absint( $row['id'] );
            $type = (string) $row['relation_type'];
            if ( ! isset( $def[ $type ] ) || $row['source_type'] !== $def[ $type ]['source_type'] ||
                $row['target_type'] !== $def[ $type ]['target_type'] ) {
                return self::bad( 'Relación #' . $id . ': tipos de extremos incorrectos para "' . $type . '".' );
            }
            $source = get_post( absint( $row['source_id'] ) );
            if ( ! $source || 'page' !== $source->post_type ) {
                return self::bad( 'Relación #' . $id . ': página de origen inexistente.' );
            }
            if ( 'product_cat' === $row['target_type'] ) {
                $target = get_term( absint( $row['target_id'] ), 'product_cat' );
            } else {
                $target = get_post( absint( $row['target_id'] ) );
            }
            if ( ! $target || is_wp_error( $target ) ) {
                return self::bad( 'Relación #' . $id . ': destino inexistente.' );
            }
            if ( '' === TaxoCommerce_Core_Country::relation( $row ) ) {
                return self::bad( 'Relación #' . $id . ': países de origen/destino incompatibles.' );
            }
        }
        return self::ok( count( $rows ) . ' relaciones comprobadas; muestra limitada, no auditoría integral.' );
    }

    private static function check_menus() {
        $stored_ids = array();
        $checked = 0;
        foreach ( array( 'ES', 'US', 'FR', 'DE', 'NL' ) as $country ) {
            $id = absint( get_option( TaxoCommerce_Core_Menu::menu_option( $country ), 0 ) );
            if ( $id && isset( $stored_ids[ $id ] ) ) {
                return self::bad( 'Menús ' . $stored_ids[ $id ] . ' y ' . $country . ' comparten ID ' . $id . '.' );
            }
            if ( $id ) {
                $stored_ids[ $id ] = $country;
            }
            $resolved = TaxoCommerce_Core_Menu::managed_menu_id( $country, false );
            if ( is_wp_error( $resolved ) ) {
                return self::bad( 'Menú ' . $country . ': ' . $resolved->get_error_message() );
            }
            if ( ! $resolved ) {
                continue;
            }
            $menu = wp_get_nav_menu_object( $resolved );
            if ( ! $menu || is_wp_error( $menu ) || (
                $menu->name !== TaxoCommerce_Core_Menu::menu_name( $country ) &&
                ! ( 'ES' === $country && 'TaxoCommerce' === $menu->name )
            ) ) {
                return self::bad( 'Menú ' . $country . ': ID o nombre nativo incoherente.' );
            }
            $items = wp_get_nav_menu_items( $resolved );
            if ( false === $items || is_wp_error( $items ) ) {
                return self::bad( 'Menú ' . $country . ': no se pueden recuperar los elementos.' );
            }
            $checked++;
        }
        return $checked ? self::ok( $checked . ' menús leídos con identidad de país diferenciada.' ) :
            self::skip( 'Aún no existen menús TaxoCommerce para verificar.' );
    }

    private static function check_extensions() {
        $modules = taxocommerce_core()->get_registered_modules();
        return is_array( $modules ) && isset( $modules['core'] )
            ? self::ok( count( $modules ) . ' módulos registrados, incluido Core.' )
            : self::bad( 'Core no figura en el registro de extensiones.' );
    }

    private static function check_vocabulary() {
        if ( 'page' !== TaxoCommerce_Core_Vocabulary::object_type( 'pages' ) ||
            'product_cat' !== TaxoCommerce_Core_Vocabulary::object_type( 'categories' ) ) {
            return self::bad( 'El editor no asigna correctamente el tipo de objeto.' );
        }
        if ( ! TaxoCommerce_Core_Vocabulary::available() ) {
            return self::skip( 'Editor cargado; tablas opcionales de vocabulario no instaladas.' );
        }
        $groups = TaxoCommerce_Core_Vocabulary::assignments( 'pages', 0 );
        return isset( $groups['rol'], $groups['tipo'] )
            ? self::ok( 'Tipos del editor y grupos de vocabulario disponibles.' )
            : self::bad( 'No se recuperan los grupos del vocabulario.' );
    }

    private static function check_report() {
        $result = TaxoCommerce_Core_Architecture_Report::summarize(
            array( array( 'id' => 10, 'role' => 'cluster' ) ), array(), array(), array(), 0
        );
        return isset( $result['clusters_count'], $result['seo_pages_count'] ) &&
            1 === (int) $result['clusters_count'] && 1 === (int) $result['seo_pages_count']
            ? self::ok( 'Resumen de arquitectura validado con un caso de prueba controlado.' )
            : self::bad( 'El resumen de arquitectura devuelve contadores incorrectos.' );
    }

    private static function check_write_uat() {
        return self::skip( 'Las escrituras sobre páginas, relaciones y menús se prueban en staging/CI, no en producción.' );
    }

    public static function render() {
        if ( ! self::allowed() ) {
            wp_die( esc_html__( 'Acceso denegado a Auto Test.', 'taxocommerce-core' ) );
        }
        $report = get_transient( self::REPORT_PREFIX . get_current_user_id() );
        $totals = is_array( $report ) && isset( $report['totals'], $report['rows'] ) ? $report['totals'] : null;
        ?>
        <div class="wrap tcc-family-screen" id="tc-core-autotest">
            <header class="tcc-page-header"><div class="tcc-page-header__content">
                <p>TaxoCommerce Core</p><h1><?php esc_html_e( 'Auto Test', 'taxocommerce-core' ); ?></h1>
                <p><?php esc_html_e( 'Comprueba las funciones principales de esta instalación después de actualizar Core.', 'taxocommerce-core' ); ?></p>
            </div></header>
            <nav class="nav-tab-wrapper" aria-label="<?php esc_attr_e( 'Navegación de TaxoCommerce Core', 'taxocommerce-core' ); ?>">
                <a class="nav-tab" href="<?php echo esc_url( admin_url( 'admin.php?page=taxocommerce-core' ) ); ?>"><?php esc_html_e( 'Overview', 'taxocommerce-core' ); ?></a>
                <a class="nav-tab" href="<?php echo esc_url( admin_url( 'admin.php?page=' . TaxoCommerce_Core_User_Guide::PAGE_SLUG ) ); ?>"><?php esc_html_e( 'User Guide', 'taxocommerce-core' ); ?></a>
                <a class="nav-tab nav-tab-active" aria-current="page" href="<?php echo esc_url( add_query_arg( array( 'page' => 'taxocommerce-core', 'tab' => 'auto-test' ), admin_url( 'admin.php' ) ) ); ?>"><?php esc_html_e( 'Auto Test', 'taxocommerce-core' ); ?></a>
            </nav>
            <style>
            #tc-core-autotest .tc-at-actions{display:flex;gap:12px;align-items:center;flex-wrap:wrap;margin:20px 0}
            #tc-core-autotest .tc-at-summary{display:flex;gap:12px;flex-wrap:wrap;margin:15px 0}
            #tc-core-autotest .tc-at-summary span{padding:12px 18px;border:1px solid #dcdcde;background:#fff;border-radius:5px}
            #tc-core-autotest .tc-at-row{display:flex;gap:12px;align-items:flex-start;padding:12px 15px;border:1px solid #dcdcde;border-bottom:0;background:#fff;max-width:1050px}
            #tc-core-autotest .tc-at-row:last-child{border-bottom:1px solid #dcdcde}
            #tc-core-autotest .tc-at-dot{display:inline-block;flex:0 0 13px;width:13px;height:13px;margin-top:4px;border-radius:50%;background:#777}
            #tc-core-autotest .tc-at-dot--pass{background:#188038}#tc-core-autotest .tc-at-dot--fail{background:#c5221f}
            #tc-core-autotest .tc-at-row strong{display:block}#tc-core-autotest .tc-at-row p{margin:4px 0;overflow-wrap:anywhere}
            #tc-core-autotest .tc-at-row--fail p{color:#b32d2e;font-weight:600}
            #tc-core-autotest .tc-at-small{font-size:12px;color:#50575e}
            </style>
            <form class="tc-at-actions" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post">
                <input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>">
                <?php wp_nonce_field( self::NONCE, 'tc_autotest_nonce' ); ?>
                <?php submit_button( __( 'Ejecutar Auto Test', 'taxocommerce-core' ), 'primary', 'submit', false ); ?>
                <span><?php esc_html_e( 'Sin cambios en el catálogo, la jerarquía ni los menús.', 'taxocommerce-core' ); ?></span>
            </form>
            <?php if ( null === $totals ) : ?>
                <p><?php esc_html_e( 'No existen resultados todavía. Ejecuta el chequeo para ver el estado de Core.', 'taxocommerce-core' ); ?></p>
            <?php else : ?>
                <p><strong><?php echo esc_html( $report['date'] ); ?></strong> · <?php echo esc_html( $report['version'] ); ?> · <?php echo esc_html( absint( $report['ms'] ) . ' ms' ); ?></p>
                <div class="tc-at-summary">
                    <span><strong style="color:#188038"><?php echo esc_html( absint( $totals['pass'] ) ); ?></strong> <?php esc_html_e( 'Correctas', 'taxocommerce-core' ); ?></span>
                    <span><strong style="color:#c5221f"><?php echo esc_html( absint( $totals['fail'] ) ); ?></strong> <?php esc_html_e( 'Errores', 'taxocommerce-core' ); ?></span>
                    <span><strong><?php echo esc_html( absint( $totals['skip'] ) ); ?></strong> <?php esc_html_e( 'No ejecutadas', 'taxocommerce-core' ); ?></span>
                </div>
                <?php foreach ( $report['rows'] as $row ) : ?>
                    <div class="tc-at-row tc-at-row--<?php echo esc_attr( $row['status'] ); ?>">
                        <span class="tc-at-dot tc-at-dot--<?php echo esc_attr( $row['status'] ); ?>" aria-hidden="true"></span>
                        <div><strong><?php echo esc_html( $row['group'] . ' · ' . $row['label'] ); ?> <span class="tc-at-small"><?php echo esc_html( $row['id'] ); ?></span></strong>
                            <p><?php echo esc_html( $row['message'] ); ?></p>
                            <span class="tc-at-small"><?php echo esc_html( absint( $row['ms'] ) . ' ms' ); ?></span></div>
                    </div>
                <?php endforeach; ?>
                <p class="description"><?php esc_html_e( 'Verde significa que pasó la comprobación indicada. Gris significa no aplicable o no ejecutado. Los resultados basados en muestras no sustituyen una auditoría completa ni las UAT de escritura en staging.', 'taxocommerce-core' ); ?></p>
            <?php endif; ?>
        </div>
        <?php
    }
}
