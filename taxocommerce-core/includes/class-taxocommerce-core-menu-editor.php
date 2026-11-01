<?php
/** Native menu additions and shared publication controls for Core. */
defined( 'ABSPATH' ) || exit;

final class TaxoCommerce_Core_Menu_Editor {
    const ACTION = 'taxocommerce_core_menu_manual';
    const SEARCH = 'taxocommerce_core_menu_objects';
    const LIMIT = 25;

    public static function register_hooks() {
        add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
        add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'handle_action' ) );
        add_action( 'wp_ajax_' . self::SEARCH, array( __CLASS__, 'search' ) );
    }

    public static function enqueue() {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only screen selection.
        $page = isset( $_GET['page'] ) && is_string( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
        if ( 'taxocommerce-core-menu' !== $page ) { return; }
        $url = plugin_dir_url( TAXOCOMMERCE_CORE_FILE ) . 'assets/';
        wp_enqueue_style( 'taxocommerce-core-menu-editor', $url . 'menu-editor.css', array(), TAXOCOMMERCE_CORE_VERSION . '-menu-1' );
        wp_enqueue_script( 'taxocommerce-core-menu-editor', $url . 'menu-editor.js', array(), TAXOCOMMERCE_CORE_VERSION . '-menu-1', true );
        wp_localize_script( 'taxocommerce-core-menu-editor', 'tcMenuEditor', array(
            'url' => admin_url( 'admin-ajax.php' ), 'nonce' => wp_create_nonce( self::SEARCH ),
            'country' => TaxoCommerce_Core_Menu::country(), 'action' => self::SEARCH, 'loading' => __( 'Cargando...', 'taxocommerce-core' ),
            'empty' => __( 'No hay elementos disponibles.', 'taxocommerce-core' ),
            'error' => __( 'No se pudo cargar la lista. Vuelve a intentarlo.', 'taxocommerce-core' ),
            'page' => __( 'Página', 'taxocommerce-core' ),
            'remove' => __( '¿Eliminar este elemento manual del menú?', 'taxocommerce-core' ),
        ) );
    }

    private static function guard() {
        if ( ! current_user_can( apply_filters( 'taxocommerce_core_manage_capability', 'manage_options' ) ) || ! current_user_can( 'edit_theme_options' ) ) {
            wp_die( esc_html__( 'No tienes permisos para gestionar este menú.', 'taxocommerce-core' ) );
        }
    }

    private static function types() {
        $types = array( 'page' => __( 'Páginas', 'taxocommerce-core' ), 'post' => __( 'Entradas', 'taxocommerce-core' ), 'category' => __( 'Categorías', 'taxocommerce-core' ) );
        if ( taxonomy_exists( 'product_cat' ) ) { $types['product_cat'] = __( 'Categorías de producto', 'taxocommerce-core' ); }
        return $types;
    }

    /** Bounded queries; search covers all published objects, not just the first page. */
    public static function objects( $type, $mode = 'recent', $page = 1, $needle = '', $country = '' ) {
        $country = TaxoCommerce_Core_Country::normalize( $country ) ?: TaxoCommerce_Core_Menu::country();
        if ( ! isset( self::types()[ $type ] ) ) { return new WP_Error( 'menu_type', 'Tipo de elemento no permitido.' ); }
        $mode = in_array( $mode, array( 'recent', 'all', 'search' ), true ) ? $mode : 'recent';
        $limit = 'recent' === $mode ? 10 : self::LIMIT;
        $offset = 'recent' === $mode ? 0 : ( max( 1, (int) $page ) - 1 ) * $limit;
        $needle = 'search' === $mode ? sanitize_text_field( $needle ) : '';
        $items = array();
        if ( in_array( $type, array( 'page', 'post' ), true ) ) {
            // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Bounded (10/25) admin selector queries indexed country metadata for international markets.
            $market_filter = 'ES' === $country ? array() : array( 'meta_query' => array( 'relation' => 'OR', array( 'key' => '_seo_pais_origen', 'value' => $country ), array( 'key' => 'dht_pilot_market', 'value' => $country ) ) );
            $query = new WP_Query( array_merge( $market_filter, array( 'post_type' => $type, 'post_status' => 'publish', 'has_password' => false,
                'posts_per_page' => $limit + 1, 'offset' => $offset, 's' => $needle, 'no_found_rows' => true,
                'orderby' => 'recent' === $mode ? 'date' : 'title', 'order' => 'recent' === $mode ? 'DESC' : 'ASC',
                'ignore_sticky_posts' => true, 'update_post_meta_cache' => false, 'update_post_term_cache' => false ) ) );
            foreach ( $query->posts as $post ) { if ( TaxoCommerce_Core_Country::post( $post->ID ) !== $country ) { continue; } $items[] = array( 'id' => (int) $post->ID, 'title' => wp_specialchars_decode( get_the_title( $post ), ENT_QUOTES ) ); }
        } else {
            $terms = get_terms( array( 'taxonomy' => $type, 'hide_empty' => false, 'number' => $limit + 1, 'offset' => $offset,
                'search' => $needle, 'orderby' => 'recent' === $mode ? 'count' : 'name', 'order' => 'recent' === $mode ? 'DESC' : 'ASC' ) );
            if ( is_wp_error( $terms ) ) { return $terms; }
            foreach ( $terms as $term ) { if ( 'product_cat' === $type && TaxoCommerce_Core_Country::term( $term->term_id ) !== $country ) { continue; } $items[] = array( 'id' => (int) $term->term_id, 'title' => wp_specialchars_decode( $term->name, ENT_QUOTES ) ); }
        }
        return array( 'items' => array_slice( $items, 0, $limit ), 'more' => 'recent' !== $mode && count( $items ) > $limit );
    }

    public static function search() {
        self::guard();
        check_ajax_referer( self::SEARCH, 'nonce' );
        $type = isset( $_POST['type'] ) && is_string( $_POST['type'] ) ? sanitize_key( wp_unslash( $_POST['type'] ) ) : '';
        $mode = isset( $_POST['mode'] ) && is_string( $_POST['mode'] ) ? sanitize_key( wp_unslash( $_POST['mode'] ) ) : 'recent';
        $page = isset( $_POST['list_page'] ) ? max( 1, min( 100000, absint( $_POST['list_page'] ) ) ) : 1;
        $needle = isset( $_POST['needle'] ) && is_string( $_POST['needle'] ) ? sanitize_text_field( wp_unslash( $_POST['needle'] ) ) : '';
        $result = self::objects( $type, $mode, $page, $needle, TaxoCommerce_Core_Menu::country( true ) );
        if ( is_wp_error( $result ) ) { wp_send_json_error( $result->get_error_message(), 400 ); }
        wp_send_json_success( $result );
    }

    /** Nonces have no id: controls are deliberately repeated on the same page. */
    private static function nonce( $action, $name = 'taxocommerce_core_nonce' ) {
        echo '<input type="hidden" name="' . esc_attr( $name ) . '" value="' . esc_attr( wp_create_nonce( $action ) ) . '">';
    }


    /** Load any existing WordPress menu without changing its country or location. */
    public static function load_controls( $country, $menu_id ) {
        $menus = wp_get_nav_menus( array( 'hide_empty' => false ) );
        if ( is_wp_error( $menus ) ) { $menus = array(); }
        $registered = get_registered_nav_menus();
        $assigned = (array) get_theme_mod( 'nav_menu_locations', array() );
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only GET menu selector; writes require action nonces.
        $explicit = isset( $_GET['tc_menu_id'] ) ? absint( $_GET['tc_menu_id'] ) : 0;
        echo '<section class="tc-menu-load" aria-labelledby="tc-menu-load-title">';
        echo '<h2 id="tc-menu-load-title">' . esc_html__( 'Cargar un menú de WordPress', 'taxocommerce-core' ) . '</h2>';
        echo '<form method="get" class="tc-menu-load-form"><input type="hidden" name="page" value="taxocommerce-core-menu">';
        echo '<input type="hidden" name="tc_country" value="' . esc_attr( $country ) . '">';
        echo '<label for="tc-menu-existing">' . esc_html__( 'Menú existente', 'taxocommerce-core' ) . '</label>';
        echo '<select name="tc_menu_id" id="tc-menu-existing">';
        echo '<option value="0"' . selected( $explicit, 0, false ) . '>' . esc_html__( 'Menú TaxoCommerce del país', 'taxocommerce-core' ) . '</option>';
        foreach ( $menus as $menu ) {
            $id = absint( $menu->term_id );
            $places = array();
            foreach ( $assigned as $location => $assigned_id ) {
                if ( $id === absint( $assigned_id ) && isset( $registered[ $location ] ) ) { $places[] = (string) $registered[ $location ]; }
            }
            $label = (string) $menu->name . ' (ID ' . $id . ')';
            if ( $places ) { $label .= ' — ' . implode( ', ', $places ); }
            echo '<option value="' . esc_attr( $id ) . '"' . selected( $explicit, $id, false ) . '>' . esc_html( $label ) . '</option>';
        }
        echo '</select><button type="submit" class="button">' . esc_html__( 'Cargar para editar', 'taxocommerce-core' ) . '</button></form>';
        echo '<form method="post" class="tc-menu-create-form" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
        self::manual_fields( 'create' );
        echo '<label for="tc-menu-new-name">' . esc_html__( 'Nombre del nuevo menú', 'taxocommerce-core' ) . '</label>';
        echo '<input type="text" id="tc-menu-new-name" name="menu_name" maxlength="200" required>';
        echo '<button type="submit" class="button">' . esc_html__( 'Crear menú nuevo', 'taxocommerce-core' ) . '</button></form>';
        echo '<p class="description">' . esc_html__( 'La selección no modifica ni copia el menú. Los cambios guardados afectan al menú elegido; crear uno nuevo no reemplaza el menú activo del tema.', 'taxocommerce-core' ) . '</p></section>';
    }

    /** Validate a native menu before any write; never fall back on an invalid ID. */
    public static function target_menu_id( $create = false ) {
        if ( isset( $_POST['tc_menu_id'] ) ) {
            $id = is_scalar( $_POST['tc_menu_id'] ) ? absint( $_POST['tc_menu_id'] ) : 0;
            if ( $id ) {
                $menu = wp_get_nav_menu_object( $id );
                return $menu && ! is_wp_error( $menu ) ? $id : new WP_Error( 'menu_missing', __( 'El menú seleccionado ya no existe.', 'taxocommerce-core' ) );
            }
        }
        return self::managed_menu( $create );
    }

    public static function create_menu( $name ) {
        $name = trim( sanitize_text_field( $name ) );
        if ( '' === $name || strlen( $name ) > 200 ) {
            return new WP_Error( 'menu_name', __( 'Indica un nombre de menú válido.', 'taxocommerce-core' ) );
        }
        return wp_create_nav_menu( $name );
    }

    public static function controls( $menu_id, $position ) {
        $menu = $menu_id ? wp_get_nav_menu_object( $menu_id ) : false;
        $locations = get_registered_nav_menus();
        $active = (array) get_theme_mod( 'nav_menu_locations', array() );
        $position = 'top' === $position ? 'top' : 'bottom';
        $selected = '';
        foreach ( $locations as $key => $label ) { if ( isset( $active[ $key ] ) && (int) $active[ $key ] === (int) $menu_id ) { $selected = $key; break; } }
        echo '<section class="tc-menu-controls" data-position="' . esc_attr( $position ) . '" aria-labelledby="tc-menu-controls-' . esc_attr( $position ) . '">';
        echo '<h2 id="tc-menu-controls-' . esc_attr( $position ) . '">' . esc_html__( 'Publicación del menú', 'taxocommerce-core' ) . '</h2><div class="tc-menu-actions">';
        $managed_id = TaxoCommerce_Core_Menu::managed_menu_id( TaxoCommerce_Core_Menu::country(), false );
        $external = $menu && absint( $managed_id ) !== absint( $menu_id );
        if ( ! $external ) {
            echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="taxocommerce_core_sync_menu">';
            echo '<input type="hidden" name="tc_country" value="' . esc_attr( TaxoCommerce_Core_Menu::country() ) . '">';
            self::nonce( 'taxocommerce_core_sync_menu' );
            echo '<button type="submit" class="button button-primary">' . ( $menu ? esc_html__( 'Sincronizar propuesta', 'taxocommerce-core' ) : esc_html__( 'Crear menú desde la jerarquía', 'taxocommerce-core' ) ) . '</button></form>';
        }
        if ( $menu ) { echo '<a class="button" href="' . esc_url( admin_url( 'nav-menus.php?action=edit&menu=' . absint( $menu_id ) ) ) . '">' . esc_html__( 'Editar manualmente en WordPress', 'taxocommerce-core' ) . '</a>'; }
        echo '</div>';
        if ( $menu && $locations ) {
            echo '<h3>' . esc_html__( 'Ubicación en el tema', 'taxocommerce-core' ) . '</h3><form class="tc-menu-actions" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
            echo '<input type="hidden" name="action" value="taxocommerce_core_activate_menu"><input type="hidden" name="tc_country" value="' . esc_attr( TaxoCommerce_Core_Menu::country() ) . '"><input type="hidden" name="tc_menu_id" value="' . esc_attr( $menu_id ) . '">';
            self::nonce( 'taxocommerce_core_activate_menu' );
            echo '<label class="screen-reader-text" for="tc-menu-location-' . esc_attr( $position ) . '">' . esc_html__( 'Ubicación en el tema', 'taxocommerce-core' ) . '</label><select data-tc-menu-location name="menu_location" id="tc-menu-location-' . esc_attr( $position ) . '">';
            foreach ( $locations as $key => $label ) { echo '<option value="' . esc_attr( $key ) . '"' . selected( $selected, $key, false ) . '>' . esc_html( $label ) . '</option>'; }
            echo '</select><button type="submit" class="button">' . esc_html__( 'Activar en esta ubicación', 'taxocommerce-core' ) . '</button></form>';
        }
        if ( get_option( TaxoCommerce_Core_Menu::BACKUP_OPTION, false ) ) {
            echo '<form class="tc-menu-restore" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="taxocommerce_core_restore_menu"><input type="hidden" name="tc_country" value="' . esc_attr( TaxoCommerce_Core_Menu::country() ) . '">';
            self::nonce( 'taxocommerce_core_restore_menu' );
            echo '<button type="submit" class="button">' . esc_html__( 'Restaurar menú anterior del tema', 'taxocommerce-core' ) . '</button></form>';
        }
        echo '</section>';
    }

    public static function render( $menu_id ) {
        $items = $menu_id ? (array) wp_get_nav_menu_items( $menu_id, array( 'post_status' => 'publish,draft' ) ) : array();
        $map = array(); $generated = 0;
        foreach ( $items as $item ) { $map[ $item->ID ] = $item; if ( self::generated( $item->ID ) ) { $generated++; } }
        echo '<p>' . esc_html__( 'Puedes editar los elementos del menú cargado. Los elementos generados desde la jerarquía SEO se conservan protegidos; los manuales pueden modificarse o eliminarse.', 'taxocommerce-core' ) . '</p>';
        echo '<div class="tc-menu-editor"><aside><h2>' . esc_html__( 'Añadir elementos al menú', 'taxocommerce-core' ) . '</h2>';
        self::box( 'page', true ); self::box( 'post' ); self::custom_box(); self::box( 'category' );
        if ( taxonomy_exists( 'product_cat' ) ) { self::box( 'product_cat' ); }
        echo '</aside><section><h2>' . esc_html__( 'Estructura del menú', 'taxocommerce-core' ) . '</h2><div class="tc-menu-structure"><div class="tc-menu-summary"><strong>' . esc_html__( 'Estructura SEO automática + elementos de WordPress', 'taxocommerce-core' ) . '</strong><p>';
        echo esc_html( sprintf(
            /* translators: 1: generated navigation items; 2: manually added items. */
            __( '%1$d automáticos · %2$d manuales. Los manuales se conservan al sincronizar.', 'taxocommerce-core' ),
            $generated,
            count( $items ) - $generated
        ) ) . '</p></div>';
        if ( ! $items ) { echo '<p>' . esc_html__( 'Todavía no hay elementos guardados. Añade elementos a la izquierda o crea el menú desde la jerarquía.', 'taxocommerce-core' ) . '</p>'; }
        echo '<ul class="tc-menu-items">';
        foreach ( $items as $item ) {
            $depth = 0; $parent = (int) $item->menu_item_parent; $seen = array( (int) $item->ID => true );
            while ( $parent && isset( $map[ $parent ] ) && ! isset( $seen[ $parent ] ) && $depth < 4 ) { $seen[ $parent ] = true; $depth++; $parent = (int) $map[ $parent ]->menu_item_parent; }
            $automatic = self::generated( $item->ID );
            echo '<li class="tc-menu-depth-' . absint( $depth ) . '"><details class="tc-menu-item"><summary><strong>' . esc_html( $item->title ) . '</strong><span>' . esc_html( $automatic ? __( 'Automático', 'taxocommerce-core' ) : __( 'Manual', 'taxocommerce-core' ) ) . '</span></summary><div class="tc-menu-item-content">';
            echo '<p><a href="' . esc_url( $item->url ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $item->url ) . '</a></p>';
            if ( $automatic ) {
                echo '<p>' . esc_html__( 'Modifica este elemento en la Jerarquía SEO y sincroniza la propuesta.', 'taxocommerce-core' ) . '</p>';
            } else {
                echo '<form method="post" class="tc-menu-item-edit" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
                self::manual_fields( 'edit' );
                echo '<input type="hidden" name="menu_item_id" value="' . absint( $item->ID ) . '">';
                echo '<p><label>' . esc_html__( 'Texto del enlace', 'taxocommerce-core' ) . ' <input type="text" name="item_title" value="' . esc_attr( $item->title ) . '" maxlength="200" required></label></p>';
                if ( 'custom' === (string) ( $item->type ?? '' ) ) {
                    echo '<p><label>URL <input type="text" name="item_url" value="' . esc_attr( $item->url ) . '" required></label></p>';
                }
                echo '<p><label>' . esc_html__( 'Elemento superior', 'taxocommerce-core' ) . ' <select name="item_parent">';
                echo '<option value="0">' . esc_html__( 'Nivel principal', 'taxocommerce-core' ) . '</option>';
                foreach ( $items as $possible ) {
                    if ( (int) $possible->ID === (int) $item->ID ) { continue; }
                    echo '<option value="' . absint( $possible->ID ) . '"' . selected( absint( $item->menu_item_parent ), absint( $possible->ID ), false ) . '>' . esc_html( $possible->title ) . '</option>';
                }
                echo '</select></label></p>';
                echo '<p><label>' . esc_html__( 'Posición', 'taxocommerce-core' ) . ' <input type="number" name="item_position" min="1" max="100000" value="' . absint( $item->menu_order ?? 1 ) . '"></label></p>';
                echo '<button type="submit" class="button button-primary">' . esc_html__( 'Guardar cambios', 'taxocommerce-core' ) . '</button></form>';
                echo '<form method="post" data-tc-menu-remove action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
                self::manual_fields( 'remove' );
                echo '<input type="hidden" name="menu_item_id" value="' . absint( $item->ID ) . '"><button type="submit" class="button-link-delete">' . esc_html__( 'Eliminar del menú', 'taxocommerce-core' ) . '</button></form>';
            }
            echo '</div></details></li>';
        }
        echo '</ul></div></section></div>';
    }

    private static function manual_fields( $task ) {
        echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '"><input type="hidden" name="task" value="' . esc_attr( $task ) . '">';
        self::nonce( self::ACTION );
        echo '<input type="hidden" name="tc_country" value="' . esc_attr( TaxoCommerce_Core_Menu::country() ) . '">';
        echo '<input type="hidden" name="tc_menu_id" value="' . esc_attr( TaxoCommerce_Core_Menu::selected_menu_id() ) . '">';
    }

    private static function box( $type, $open = false ) {
        $types = self::types(); $list = self::objects( $type );
        echo '<details class="tc-menu-box"' . ( $open ? ' open' : '' ) . '><summary>' . esc_html( $types[ $type ] ) . '</summary><form class="tc-menu-picker" data-type="' . esc_attr( $type ) . '" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
        self::manual_fields( 'add' );
        echo '<input type="hidden" name="object_type" value="' . esc_attr( $type ) . '"><div class="tc-menu-tabs">';
        foreach ( array( 'recent' => in_array( $type, array( 'page', 'post' ), true ) ? __( 'Más reciente', 'taxocommerce-core' ) : __( 'Más utilizadas', 'taxocommerce-core' ), 'all' => __( 'Ver todo', 'taxocommerce-core' ), 'search' => __( 'Buscar', 'taxocommerce-core' ) ) as $mode => $label ) {
            echo '<button type="button" data-mode="' . esc_attr( $mode ) . '" aria-pressed="' . ( 'recent' === $mode ? 'true' : 'false' ) . '">' . esc_html( $label ) . '</button>';
        }
        echo '</div><label class="tc-menu-search" hidden>' . esc_html__( 'Buscar por nombre', 'taxocommerce-core' ) . '<input type="search" maxlength="160"></label><div class="tc-menu-selection" hidden></div><div class="tc-menu-checklist" aria-live="polite">';
        if ( is_wp_error( $list ) || ! $list['items'] ) { echo '<p>' . esc_html__( 'No hay elementos disponibles.', 'taxocommerce-core' ) . '</p>'; }
        if ( ! is_wp_error( $list ) ) { foreach ( $list['items'] as $row ) { echo '<label><input type="checkbox" name="object_ids[]" value="' . absint( $row['id'] ) . '"> ' . esc_html( $row['title'] ) . '</label>'; } }
        echo '</div><div class="tc-menu-pages" hidden><button type="button" data-direction="-1" class="button">' . esc_html__( 'Anterior', 'taxocommerce-core' ) . '</button><span></span><button type="button" data-direction="1" class="button">' . esc_html__( 'Siguiente', 'taxocommerce-core' ) . '</button></div>';
        echo '<div class="tc-menu-box-actions"><label><input type="checkbox" data-select-all> ' . esc_html__( 'Seleccionar todo', 'taxocommerce-core' ) . '</label><button class="button" type="submit">' . esc_html__( 'Añadir al menú', 'taxocommerce-core' ) . '</button></div></form></details>';
    }

    private static function custom_box() {
        echo '<details class="tc-menu-box"><summary>' . esc_html__( 'Enlaces personalizados', 'taxocommerce-core' ) . '</summary><form class="tc-menu-custom" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
        self::manual_fields( 'custom' );
        echo '<p><label>URL<input type="text" name="custom_url" placeholder="https://" required></label></p><p><label>' . esc_html__( 'Texto del enlace', 'taxocommerce-core' ) . '<input type="text" name="custom_title" maxlength="200" required></label></p><button type="submit" class="button">' . esc_html__( 'Añadir al menú', 'taxocommerce-core' ) . '</button></form></details>';
    }

    public static function generated( $item_id ) {
        return 1 === (int) get_post_meta( $item_id, TaxoCommerce_Core_Menu::GENERATED_META, true );
    }

    /** Use the same country-aware identity validation as automatic menus. */
    private static function managed_menu( $create = false ) {
        return TaxoCommerce_Core_Menu::managed_menu_id( TaxoCommerce_Core_Menu::country( true ), $create );
    }

    /** Shared by the authenticated controller and regression tests. */
    public static function add_objects( $type, $ids ) {
        if ( ! isset( self::types()[ $type ] ) ) { return new WP_Error( 'menu_type', 'Tipo de elemento no permitido.' ); }
        $ids = array_values( array_unique( array_filter( array_map( 'absint', (array) $ids ) ) ) );
        if ( ! $ids || count( $ids ) > 50 ) { return new WP_Error( 'menu_selection', 'Selecciona entre 1 y 50 elementos.' ); }
        $data = array();
        foreach ( $ids as $id ) {
            if ( in_array( $type, array( 'page', 'post' ), true ) ) {
                $post = get_post( $id );
                if ( ! $post || $post->post_type !== $type || 'publish' !== $post->post_status || '' !== $post->post_password ) { return new WP_Error( 'menu_post', 'La selección contiene un contenido no publicado o no válido.' ); }
                if ( TaxoCommerce_Core_Country::post( $id ) !== TaxoCommerce_Core_Menu::country( true ) ) { return new WP_Error( 'menu_country', 'El contenido pertenece a otro país.' ); }
                $data[] = array( 'menu-item-object-id' => $id, 'menu-item-object' => $type, 'menu-item-type' => 'post_type', 'menu-item-title' => wp_slash( get_the_title( $post ) ) );
            } else {
                $term = get_term( $id, $type );
                if ( ! $term || is_wp_error( $term ) ) { return new WP_Error( 'menu_term', 'La categoría seleccionada no existe.' ); }
                if ( 'product_cat' === $type && TaxoCommerce_Core_Country::term( $id ) !== TaxoCommerce_Core_Menu::country( true ) ) { return new WP_Error( 'menu_country', 'La categoría pertenece a otro país.' ); }
                $data[] = array( 'menu-item-object-id' => $id, 'menu-item-object' => $type, 'menu-item-type' => 'taxonomy', 'menu-item-title' => wp_slash( $term->name ) );
            }
        }
        $menu_id = self::target_menu_id( true );
        if ( is_wp_error( $menu_id ) ) { return $menu_id; }
        $added = 0;
        foreach ( $data as $item ) {
            $result = wp_update_nav_menu_item( $menu_id, 0, array_merge( $item, array( 'menu-item-status' => 'publish', 'menu-item-parent-id' => 0, 'menu-item-position' => 0 ) ) );
            if ( is_wp_error( $result ) ) { return new WP_Error( 'menu_write', sprintf( 'Se añadieron %d elementos; no se pudo completar la selección.', $added ) ); }
            $added++;
        }
        return $added;
    }

    public static function add_link( $title, $url ) {
        $title = sanitize_text_field( $title );
        $url = esc_url_raw( $url, array( 'http', 'https', 'mailto', 'tel' ) );
        if ( '' === $title || '' === $url ) { return new WP_Error( 'menu_link', 'Indica un texto y una URL válidos.' ); }
        $menu_id = self::managed_menu( true );
        if ( is_wp_error( $menu_id ) ) { return $menu_id; }
        return wp_update_nav_menu_item( $menu_id, 0, array( 'menu-item-title' => wp_slash( $title ), 'menu-item-url' => $url,
            'menu-item-type' => 'custom', 'menu-item-status' => 'publish', 'menu-item-parent-id' => 0, 'menu-item-position' => 0 ) );
    }

    public static function remove_item( $id ) {
        $menu_id = self::target_menu_id();
        if ( is_wp_error( $menu_id ) ) { return $menu_id; }
        $items = $menu_id ? (array) wp_get_nav_menu_items( $menu_id, array( 'post_status' => 'publish,draft' ) ) : array();
        $target = null;
        foreach ( $items as $item ) { if ( (int) $item->ID === (int) $id ) { $target = $item; break; } }
        if ( ! $target || self::generated( $id ) ) { return new WP_Error( 'menu_item', 'Solo puedes eliminar elementos manuales de este menú.' ); }
        foreach ( $items as $item ) { if ( (int) $item->menu_item_parent === (int) $id ) { update_post_meta( $item->ID, '_menu_item_menu_item_parent', (int) $target->menu_item_parent ); } }
        return wp_delete_post( $id, true ) ? true : new WP_Error( 'menu_delete', 'No se pudo eliminar el elemento.' );
    }


    /**
     * Update a manual menu item without changing its WordPress type or object.
     * Reject cross-menu parents, circular nesting and writes to SEO-generated items.
     */
    public static function edit_item( $id, $title, $url, $parent_id, $position ) {
        $menu_id = self::target_menu_id();
        if ( is_wp_error( $menu_id ) ) { return $menu_id; }
        $items = $menu_id ? (array) wp_get_nav_menu_items( $menu_id, array( 'post_status' => 'publish,draft' ) ) : array();
        $map = array();
        foreach ( $items as $item ) { $map[ absint( $item->ID ) ] = $item; }
        $id = absint( $id );
        if ( ! isset( $map[ $id ] ) || self::generated( $id ) ) {
            return new WP_Error( 'menu_item', __( 'Solo puedes editar elementos manuales del menú cargado.', 'taxocommerce-core' ) );
        }
        $item = $map[ $id ];
        $title = sanitize_text_field( $title );
        $parent_id = absint( $parent_id );
        $position = absint( $position );
        if ( '' === trim( $title ) || ! $position || $position > 100000 ) {
            return new WP_Error( 'menu_edit', __( 'Revisa el título y la posición del elemento.', 'taxocommerce-core' ) );
        }
        $cursor = $parent_id;
        $visited = array( $id => true );
        while ( $cursor ) {
            if ( ! isset( $map[ $cursor ] ) || isset( $visited[ $cursor ] ) ) {
                return new WP_Error( 'menu_parent', __( 'El superior debe pertenecer al mismo menú y no puede crear un ciclo.', 'taxocommerce-core' ) );
            }
            $visited[ $cursor ] = true;
            $cursor = absint( $map[ $cursor ]->menu_item_parent );
        }
        $type = (string) ( $item->type ?? 'custom' );
        $args = array(
            'menu-item-title'       => wp_slash( $title ),
            'menu-item-type'        => $type,
            'menu-item-object'      => (string) ( $item->object ?? 'custom' ),
            'menu-item-object-id'   => absint( $item->object_id ?? 0 ),
            'menu-item-parent-id'   => $parent_id,
            'menu-item-position'    => $position,
            'menu-item-status'      => 'publish',
            'menu-item-attr-title'  => wp_slash( (string) ( $item->attr_title ?? '' ) ),
            'menu-item-description' => wp_slash( (string) ( $item->description ?? '' ) ),
            'menu-item-target'      => (string) ( $item->target ?? '' ),
            'menu-item-classes'     => implode( ' ', (array) ( $item->classes ?? array() ) ),
            'menu-item-xfn'         => (string) ( $item->xfn ?? '' ),
        );
        if ( 'custom' === $type ) {
            $url = esc_url_raw( $url, array( 'http', 'https', 'mailto', 'tel' ) );
            if ( '' === $url ) { return new WP_Error( 'menu_link', __( 'Introduce una URL válida.', 'taxocommerce-core' ) ); }
            $args['menu-item-url'] = $url;
        }
        return wp_update_nav_menu_item( $menu_id, $id, $args );
    }

    public static function handle_action() {
        self::guard();
        check_admin_referer( self::ACTION, 'taxocommerce_core_nonce' );
        $task = isset( $_POST['task'] ) && is_string( $_POST['task'] ) ? sanitize_key( wp_unslash( $_POST['task'] ) ) : '';
        if ( 'add' === $task ) {
            $type = isset( $_POST['object_type'] ) && is_string( $_POST['object_type'] ) ? sanitize_key( wp_unslash( $_POST['object_type'] ) ) : '';
            $ids = isset( $_POST['object_ids'] ) && is_array( $_POST['object_ids'] ) ? array_map( 'absint', wp_unslash( $_POST['object_ids'] ) ) : array();
            $result = self::add_objects( $type, $ids );
            $message = is_wp_error( $result ) ? $result->get_error_message() : sprintf(
                /* translators: %d: number of WordPress objects newly added to the menu. */
                __( '%d elementos añadidos. Se conservarán al sincronizar.', 'taxocommerce-core' ),
                $result
            );
        } elseif ( 'custom' === $task ) {
            $title = isset( $_POST['custom_title'] ) && is_string( $_POST['custom_title'] ) ? sanitize_text_field( wp_unslash( $_POST['custom_title'] ) ) : '';
            $url = isset( $_POST['custom_url'] ) && is_string( $_POST['custom_url'] ) ? esc_url_raw( wp_unslash( $_POST['custom_url'] ), array( 'http', 'https', 'mailto', 'tel' ) ) : '';
            $result = self::add_link( $title, $url );
            $message = is_wp_error( $result ) ? $result->get_error_message() : __( 'Enlace personalizado añadido.', 'taxocommerce-core' );
        } elseif ( 'remove' === $task ) {
            $result = self::remove_item( isset( $_POST['menu_item_id'] ) ? absint( $_POST['menu_item_id'] ) : 0 );
            $message = is_wp_error( $result ) ? $result->get_error_message() : __( 'Elemento manual eliminado del menú.', 'taxocommerce-core' );
        } elseif ( 'create' === $task ) {
            $name = isset( $_POST['menu_name'] ) && is_string( $_POST['menu_name'] ) ? sanitize_text_field( wp_unslash( $_POST['menu_name'] ) ) : '';
            $result = self::create_menu( $name );
            $message = is_wp_error( $result ) ? $result->get_error_message() : __( 'Menú nuevo creado. Puedes editarlo sin modificar los existentes.', 'taxocommerce-core' );
        } elseif ( 'edit' === $task ) {
            $id = isset( $_POST['menu_item_id'] ) ? absint( $_POST['menu_item_id'] ) : 0;
            $title = isset( $_POST['item_title'] ) && is_string( $_POST['item_title'] ) ? sanitize_text_field( wp_unslash( $_POST['item_title'] ) ) : '';
            $url = isset( $_POST['item_url'] ) && is_string( $_POST['item_url'] ) ? esc_url_raw( wp_unslash( $_POST['item_url'] ), array( 'http', 'https', 'mailto', 'tel' ) ) : '';
            $parent = isset( $_POST['item_parent'] ) ? absint( $_POST['item_parent'] ) : 0;
            $position = isset( $_POST['item_position'] ) ? absint( $_POST['item_position'] ) : 1;
            $result = self::edit_item( $id, $title, $url, $parent, $position );
            $message = is_wp_error( $result ) ? $result->get_error_message() : __( 'Elemento del menú actualizado.', 'taxocommerce-core' );
        } else {
            $result = new WP_Error( 'menu_action', 'Acción no válida.' ); $message = $result->get_error_message();
        }
        $selected_id = 'create' === $task && ! is_wp_error( $result )
            ? absint( $result ) : TaxoCommerce_Core_Menu::selected_menu_id( true );
        wp_safe_redirect( add_query_arg( array( 'page' => 'taxocommerce-core-menu', 'tc_country' => TaxoCommerce_Core_Menu::country( true ), 'tc_menu_id' => $selected_id, 'tc_result' => is_wp_error( $result ) ? 'error' : 'ok', 'tc_message' => $message ), admin_url( 'admin.php' ) ) );
        exit;
    }
}
