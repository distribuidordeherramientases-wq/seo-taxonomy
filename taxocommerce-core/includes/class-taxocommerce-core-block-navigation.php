<?php
/**
 * Edit a referenced Navigation block without confusing it with a classic nav_menu.
 * Inline template navigation is intentionally left to the Site Editor.
 */
defined( 'ABSPATH' ) || exit;

final class TaxoCommerce_Core_Block_Navigation {
    const ACTION = 'taxocommerce_core_save_block_navigation';
    const CREATE = 'taxocommerce_core_create_block_navigation';
    const ACTIVATE = 'taxocommerce_core_activate_block_navigation';
    const ADD_LINK = 'taxocommerce_core_add_block_navigation_link';
    const ADD_OBJECTS = 'taxocommerce_core_add_block_navigation_objects';
    const REMOVE = 'taxocommerce_core_remove_block_navigation_link';
    const DELETE = 'taxocommerce_core_delete_block_navigation';

    public static function register_hooks() {
        add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'save' ) );
        add_action( 'admin_post_' . self::CREATE, array( __CLASS__, 'create' ) );
        add_action( 'admin_post_' . self::ACTIVATE, array( __CLASS__, 'activate' ) );
        add_action( 'admin_post_' . self::ADD_LINK, array( __CLASS__, 'add_link' ) );
        add_action( 'admin_post_' . self::ADD_OBJECTS, array( __CLASS__, 'add_objects' ) );
        add_action( 'admin_post_' . self::REMOVE, array( __CLASS__, 'remove_link' ) );
        add_action( 'admin_post_' . self::DELETE, array( __CLASS__, 'delete' ) );
    }

    /** Collect referenced navigation posts and inline navigation from the site's header templates. */
    public static function header_references() {
        if ( ! function_exists( 'get_block_templates' ) || ! function_exists( 'parse_blocks' ) ) {
            return array( 'menus' => array(), 'inline' => false );
        }
        $menus = array();
        $inline = false;
        $parts = get_block_templates( array( 'area' => 'header' ), 'wp_template_part' );
        if ( ! is_array( $parts ) ) { $parts = array(); }

        // The active header can come from page, single, archive or a custom
        // block template. Limiting discovery to three template slugs misses it.
        $templates = get_block_templates( array(), 'wp_template' );
        $used_slugs = array();
        if ( is_array( $templates ) ) {
            $order = array( 'front-page' => 0, 'home' => 1, 'page' => 2, 'index' => 3, 'single' => 4 );
            usort( $templates, static function ( $a, $b ) use ( $order ) {
                return ( $order[ $a->slug ] ?? 10 ) - ( $order[ $b->slug ] ?? 10 );
            } );
            foreach ( $templates as $template ) {
                $found = array();
                $template_parts = array();
                self::scan( parse_blocks( (string) $template->content ), $found, $inline, $template_parts );
                foreach ( $found as $id ) { $menus[ $id ] = (string) $template->title; }
                foreach ( $template_parts as $slug ) { $used_slugs[ $slug ] = true; }
            }
        }

        // Include all actual header template parts, even when an edited theme
        // refers to them from a template whose slug is not one of the defaults.
        // Identical navigation IDs are deduplicated. No menu is created here.
        foreach ( $parts as $part ) {
            $found = array();
            $nested_parts = array();
            self::scan( parse_blocks( (string) $part->content ), $found, $inline, $nested_parts );
            foreach ( $found as $id ) {
                $menus[ $id ] = (string) $part->title;
            }
        }
        foreach ( $menus as $id => $label ) {
            $post = get_post( $id );
            if ( ! $post || 'wp_navigation' !== $post->post_type || 'publish' !== $post->post_status ) {
                unset( $menus[ $id ] );
            }
        }
        return array( 'menus' => $menus, 'inline' => $inline );
    }

    private static function scan( $blocks, &$references, &$inline, &$parts ) {
        foreach ( $blocks as $block ) {
            $name = $block['blockName'] ?? '';
            $attrs = $block['attrs'] ?? array();
            if ( 'core/template-part' === $name && ! empty( $attrs['slug'] ) ) {
                $parts[] = (string) $attrs['slug'];
            }
            if ( 'core/navigation' === $name ) {
                if ( ! empty( $attrs['ref'] ) ) {
                    $references[] = absint( $attrs['ref'] );
                } else {
                    $inline = true;
                }
            }
            if ( ! empty( $block['innerBlocks'] ) ) {
                self::scan( $block['innerBlocks'], $references, $inline, $parts );
            }
        }
    }

    /** Only standard Navigation Link and Submenu blocks are edited; other blocks survive unchanged. */
    private static function links( $blocks, &$links, $depth = 0 ) {
        foreach ( $blocks as $block ) {
            $name = $block['blockName'] ?? '';
            if ( in_array( $name, array( 'core/navigation-link', 'core/navigation-submenu' ), true ) ) {
                $attrs = $block['attrs'] ?? array();
                $links[] = array(
                    'label' => (string) ( $attrs['label'] ?? '' ),
                    'url' => (string) ( $attrs['url'] ?? '' ),
                    'custom' => empty( $attrs['id'] ),
                    'editable' => '' === trim( (string) ( $block['innerHTML'] ?? '' ) ),
                    'depth' => $depth,
                    'removable' => 'core/navigation-link' === $name && empty( $block['innerBlocks'] ) && '' === trim( (string) ( $block['innerHTML'] ?? '' ) ),
                );
            }
            if ( ! empty( $block['innerBlocks'] ) ) { self::links( $block['innerBlocks'], $links, $depth + ( 'core/navigation-submenu' === $name ? 1 : 0 ) ); }
        }
    }

    /**
     * Same screen layout as classic menus: loading, publication controls and
     * two-column object picker / nested structure. The storage stays native:
     * wp_navigation blocks here, nav_menu terms in classic themes.
     */
    public static function render() {
        if ( ! function_exists( 'wp_is_block_theme' ) || ! wp_is_block_theme() ) { return; }
        $found = self::header_references();
        $menus = $found['menus'];
        $other_menus = get_posts( array(
            'post_type' => 'wp_navigation', 'post_status' => 'publish',
            'posts_per_page' => -1, 'orderby' => 'title', 'order' => 'ASC',
        ) );
        foreach ( is_array( $other_menus ) ? $other_menus : array() as $nav ) {
            if ( ! isset( $menus[ $nav->ID ] ) ) {
                $menus[ $nav->ID ] = __( 'Navegación existente (sin referencia de cabecera confirmada)', 'taxocommerce-core-catalog-content-management-for-woocommerce' );
            }
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only navigation selection.
        $requested = isset( $_GET['tc_nav_id'] ) && is_scalar( $_GET['tc_nav_id'] ) ? absint( $_GET['tc_nav_id'] ) : 0;
        $selected = $requested && isset( $menus[ $requested ] ) ? $requested
            : ( 1 === count( $found['menus'] ) ? (int) key( $found['menus'] )
                : ( 1 === count( $menus ) ? (int) key( $menus ) : 0 ) );

        echo '<section class="tc-menu-load" aria-labelledby="tc-block-menu-load-title">';
        echo '<h2 id="tc-block-menu-load-title">' . esc_html__( 'Cargar un menú de WordPress', 'taxocommerce-core-catalog-content-management-for-woocommerce' ) . '</h2>';
        echo '<form method="get" class="tc-menu-load-form">';
        echo '<input type="hidden" name="page" value="taxocommerce-core-menu">';
        echo '<label for="tc-block-menu-existing">' . esc_html__( 'Menú existente', 'taxocommerce-core-catalog-content-management-for-woocommerce' ) . '</label>';
        echo '<select name="tc_nav_id" id="tc-block-menu-existing">';
        echo '<option value="0">' . esc_html__( 'Selecciona una navegación', 'taxocommerce-core-catalog-content-management-for-woocommerce' ) . '</option>';
        foreach ( $menus as $id => $source ) {
            $post = get_post( $id );
            if ( ! $post || 'wp_navigation' !== $post->post_type || 'publish' !== $post->post_status ) { continue; }
            echo '<option value="' . absint( $id ) . '"' . selected( $selected, $id, false ) . '>' .
                esc_html( $post->post_title . ' (ID ' . $id . ' — ' . $source . ')' ) . '</option>';
        }
        echo '</select><button class="button" type="submit">' . esc_html__( 'Cargar para editar', 'taxocommerce-core-catalog-content-management-for-woocommerce' ) . '</button></form>';
        echo '<form method="post" class="tc-menu-create-form" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
        echo '<input type="hidden" name="action" value="' . esc_attr( self::CREATE ) . '">';
        wp_nonce_field( self::CREATE );
        echo '<label for="tc-block-menu-new-name">' . esc_html__( 'Nombre del nuevo menú', 'taxocommerce-core-catalog-content-management-for-woocommerce' ) . '</label>';
        echo '<input id="tc-block-menu-new-name" type="text" name="menu_name" maxlength="200" required>';
        echo '<button type="submit" class="button">' . esc_html__( 'Crear menú nuevo', 'taxocommerce-core-catalog-content-management-for-woocommerce' ) . '</button></form>';
        echo '<p class="description">' . esc_html__( 'La selección no modifica ni copia el menú. Los cambios guardados afectan al menú elegido; crear uno nuevo no reemplaza el menú activo del tema.', 'taxocommerce-core-catalog-content-management-for-woocommerce' ) . '</p>';
        echo '</section>';
        if ( $found['inline'] ) {
            echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'El tema contiene una navegación integrada directamente en una plantilla. No aparece en los menús clásicos ni se puede guardar como un menú independiente. Para editar exactamente esa navegación, abre el Editor del sitio.', 'taxocommerce-core-catalog-content-management-for-woocommerce' ) . '</p></div>';
        }
        if ( ! $selected ) {
            echo '<p class="notice notice-info inline">' . esc_html__( 'Selecciona un menú de WordPress existente o crea uno nuevo para añadir y editar sus elementos.', 'taxocommerce-core-catalog-content-management-for-woocommerce' ) . '</p>';
            echo '<p>' . esc_html__( 'No se ha confirmado una única navegación de cabecera editable. Selecciona una navegación de bloques para examinarla, o abre el Editor del sitio si la cabecera utiliza enlaces integrados. No se modificará ningún menú por suposición.', 'taxocommerce-core-catalog-content-management-for-woocommerce' ) . '</p>';
            return;
        }
        $post = get_post( $selected );
        if ( ! $post || 'wp_navigation' !== $post->post_type || 'publish' !== $post->post_status ) { return; }
        $hash = hash( 'sha256', $post->post_content );
        $links = array();
        self::links( parse_blocks( $post->post_content ), $links );
        echo '<h2>' . esc_html( $post->post_title ) . '</h2>';
        echo '<p>' . esc_html__( 'Identificador del menú:', 'taxocommerce-core-catalog-content-management-for-woocommerce' ) . ' <code>' . absint( $selected ) . '</code></p>';
        echo '<form id="tc-menu-save-form" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
        echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '">';
        echo '<input type="hidden" name="tc_nav_id" value="' . absint( $selected ) . '">';
        echo '<input type="hidden" name="content_hash" value="' . esc_attr( $hash ) . '">';
        echo '<input type="hidden" name="menu_title_hash" value="' . esc_attr( hash( 'sha256', $post->post_title ) ) . '">';
        wp_nonce_field( self::ACTION . '_' . $selected );
        echo '<label for="tc-menu-save-name">' . esc_html__( 'Nombre del menú', 'taxocommerce-core-catalog-content-management-for-woocommerce' ) . '</label> ';
        echo '<input id="tc-menu-save-name" type="text" name="menu_name" maxlength="200" value="' . esc_attr( $post->post_title ) . '" required>';
        echo '</form>';
        echo '<p class="tc-menu-unsaved" data-tc-menu-unsaved hidden>' . esc_html__( 'Hay cambios pendientes de guardar.', 'taxocommerce-core-catalog-content-management-for-woocommerce' ) . '</p>';
        self::publication_controls( $selected, $found, 'top' );
        echo '<p>' . esc_html__( 'Gestiona las páginas, entradas, categorías y enlaces de este menú nativo WordPress.', 'taxocommerce-core-catalog-content-management-for-woocommerce' ) . '</p>';
        echo '<div class="tc-menu-editor"><aside><h2>' . esc_html__( 'Añadir elementos al menú', 'taxocommerce-core-catalog-content-management-for-woocommerce' ) . '</h2>';
        TaxoCommerce_Core_Menu_Editor::render_block_pickers( $selected, $hash );
        echo '</aside><section><h2>' . esc_html__( 'Estructura del menú', 'taxocommerce-core-catalog-content-management-for-woocommerce' ) . '</h2>';
        echo '<div class="tc-menu-structure"><div class="tc-menu-summary"><strong>' . esc_html__( 'Elementos del menú WordPress', 'taxocommerce-core-catalog-content-management-for-woocommerce' ) . '</strong><p>';
        echo absint( count( $links ) ) . ' ' . esc_html__( 'Elementos del menú WordPress', 'taxocommerce-core-catalog-content-management-for-woocommerce' ) . '</p></div>';
        if ( ! $links ) {
            echo '<p>' . esc_html__( 'Este menú está vacío. Añade páginas, entradas, categorías o enlaces desde el panel izquierdo.', 'taxocommerce-core-catalog-content-management-for-woocommerce' ) . '</p>';
        }
        echo '<ul class="tc-menu-items">';
        foreach ( $links as $index => $link ) {
            $depth = min( 4, absint( $link['depth'] ) );
            echo '<li class="tc-menu-depth-' . esc_attr( $depth ) . '"><details class="tc-menu-item"><summary><strong>' .
                esc_html( $link['label'] ) . '</strong><span>' . esc_html__( 'Manual', 'taxocommerce-core-catalog-content-management-for-woocommerce' ) . '</span></summary>';
            echo '<div class="tc-menu-item-content">';
            if ( $link['url'] ) {
                echo '<p><a href="' . esc_url( $link['url'] ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $link['url'] ) . '</a></p>';
            }
            echo '<div class="tc-menu-item-edit"><p><label>' . esc_html__( 'Texto del enlace', 'taxocommerce-core-catalog-content-management-for-woocommerce' ) . ' ';
            echo '<input form="tc-menu-save-form" type="text" name="nav_links[' . absint( $index ) . '][label]" value="' . esc_attr( $link['label'] ) . '" maxlength="200"' . ( $link['editable'] ? ' required' : ' readonly' ) . '></label></p>';
            if ( $link['custom'] ) {
                echo '<p><label>URL <input form="tc-menu-save-form" type="text" name="nav_links[' . absint( $index ) . '][url]" value="' . esc_attr( $link['url'] ) . '"' . ( $link['editable'] ? ' required' : ' readonly' ) . '></label></p>';
            }
            if ( ! $link['editable'] ) {
                echo '<p class="description">' . esc_html__( 'Este bloque requiere el Editor del sitio para cambios avanzados.', 'taxocommerce-core-catalog-content-management-for-woocommerce' ) . '</p>';
            } else {
                echo '<p class="description">' . esc_html__( 'Pulsa Guardar menú para aplicar estos cambios.', 'taxocommerce-core-catalog-content-management-for-woocommerce' ) . '</p>';
            }
            echo '</div>';
            if ( $link['removable'] ) {
                echo '<form method="post" data-tc-menu-remove action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
                echo '<input type="hidden" name="action" value="' . esc_attr( self::REMOVE ) . '">';
                echo '<input type="hidden" name="tc_nav_id" value="' . absint( $selected ) . '">';
                echo '<input type="hidden" name="content_hash" value="' . esc_attr( $hash ) . '">';
                echo '<input type="hidden" name="item_index" value="' . absint( $index ) . '">';
                wp_nonce_field( self::REMOVE . '_' . $selected );
                echo '<button type="submit" class="button-link-delete">' . esc_html__( 'Eliminar del menú', 'taxocommerce-core-catalog-content-management-for-woocommerce' ) . '</button></form>';
            }
            echo '</div></details></li>';
        }
        echo '</ul></div></section></div>';
        self::publication_controls( $selected, $found, 'bottom' );
    }

    /** Consistent publication controls; only the WordPress storage API differs. */
    private static function publication_controls( $id, $found, $position ) {
        echo '<section class="tc-menu-controls" data-position="' . esc_attr( $position ) . '">';
        echo '<h2>' . esc_html__( 'Publicación del menú', 'taxocommerce-core-catalog-content-management-for-woocommerce' ) . '</h2>';
        echo '<div class="tc-menu-actions">';
        $edit_link = get_edit_post_link( $id, 'raw' );
        if ( $edit_link ) {
            echo '<a class="button" href="' . esc_url( $edit_link ) . '">' . esc_html__( 'Editar manualmente en WordPress', 'taxocommerce-core-catalog-content-management-for-woocommerce' ) . '</a>';
        }
        echo '<button form="tc-menu-save-form" class="button button-primary" type="submit">' . esc_html__( 'Guardar menú', 'taxocommerce-core-catalog-content-management-for-woocommerce' ) . '</button>';
        echo '</div>';
        if ( isset( $found['menus'][ $id ] ) ) {
            echo '<p class="description">' . esc_html__( 'Este contenido está referenciado desde una plantilla de cabecera. Guardarlo modifica esa navegación de bloques, no los menús clásicos.', 'taxocommerce-core-catalog-content-management-for-woocommerce' ) . '</p>';
        } else {
            echo '<p class="description">' . esc_html__( 'Esta navegación existe en WordPress, pero su uso en la cabecera no está confirmado. Comprueba que sus enlaces coinciden con el menú público antes de guardar.', 'taxocommerce-core-catalog-content-management-for-woocommerce' ) . '</p>';
        }
        $targets = self::editable_header_parts();
        if ( $targets ) {
            echo '<h3>' . esc_html__( 'Ubicación en el tema', 'taxocommerce-core-catalog-content-management-for-woocommerce' ) . '</h3>';
            echo '<form method="post" class="tc-menu-actions" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
            echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTIVATE ) . '">';
            echo '<input type="hidden" name="tc_nav_id" value="' . absint( $id ) . '">';
            wp_nonce_field( self::ACTIVATE . '_' . $id );
            echo '<label class="screen-reader-text" for="tc-block-menu-location-' . esc_attr( $position ) . '">' . esc_html__( 'Ubicación en el tema', 'taxocommerce-core-catalog-content-management-for-woocommerce' ) . '</label>';
            echo '<select id="tc-block-menu-location-' . esc_attr( $position ) . '" data-tc-menu-location name="tc_header_id">';
            foreach ( $targets as $target ) {
                echo '<option value="' . esc_attr( $target['id'] ) . '">' . esc_html( $target['title'] ) . '</option>';
            }
            echo '</select><button type="submit" class="button">' . esc_html__( 'Activar en esta ubicación', 'taxocommerce-core-catalog-content-management-for-woocommerce' ) . '</button></form>';
        } else {
            echo '<p class="description">' . esc_html__( 'La cabecera procede de una plantilla del tema no personalizada o contiene varias navegaciones. Para activarla, usa el Editor del sitio; no se cambiará la plantilla por suposición.', 'taxocommerce-core-catalog-content-management-for-woocommerce' ) . '</p>';
        }
        if ( 'top' === $position && ! isset( $found['menus'][ $id ] ) && current_user_can( 'delete_post', $id ) ) {
            echo '<form class="tc-menu-delete-form" data-tc-menu-delete method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
            echo '<input type="hidden" name="action" value="' . esc_attr( self::DELETE ) . '">';
            echo '<input type="hidden" name="tc_nav_id" value="' . absint( $id ) . '">';
            wp_nonce_field( self::DELETE . '_' . $id );
            echo '<label><input type="checkbox" name="confirm_delete" value="1" required> ' . esc_html__( 'Confirmar eliminación definitiva', 'taxocommerce-core-catalog-content-management-for-woocommerce' ) . '</label>';
            echo '<button type="submit" class="button-link-delete">' . esc_html__( 'Eliminar menú', 'taxocommerce-core-catalog-content-management-for-woocommerce' ) . '</button></form>';
        }
        echo '<p><a class="button" href="' . esc_url( admin_url( 'site-editor.php' ) ) . '">' . esc_html__( 'Editar cabecera en el Editor del sitio', 'taxocommerce-core-catalog-content-management-for-woocommerce' ) . '</a></p></section>';
    }

    /** Delete only an unreferenced menu after a separate explicit confirmation. */
    public static function delete() {
        if ( ! current_user_can( 'edit_theme_options' ) ) {
            wp_die( esc_html__( 'No tienes permisos para gestionar este menú.', 'taxocommerce-core-catalog-content-management-for-woocommerce' ) );
        }
        $id = isset( $_POST['tc_nav_id'] ) && is_scalar( $_POST['tc_nav_id'] ) ? absint( $_POST['tc_nav_id'] ) : 0;
        check_admin_referer( self::DELETE . '_' . $id );
        $confirmed = isset( $_POST['confirm_delete'] ) && is_scalar( $_POST['confirm_delete'] )
            && '1' === sanitize_text_field( wp_unslash( $_POST['confirm_delete'] ) );
        if ( ! $confirmed ) {
            self::redirect( $id, 'error', __( 'Confirma la eliminación del menú.', 'taxocommerce-core-catalog-content-management-for-woocommerce' ) );
        }
        $post = $id ? get_post( $id ) : false;
        $references = self::header_references();
        if ( ! $post || 'wp_navigation' !== $post->post_type || 'publish' !== $post->post_status
            || ! current_user_can( 'delete_post', $id ) || isset( $references['menus'][ $id ] ) ) {
            self::redirect( $id, 'error', __( 'El menú está protegido o no puede eliminarse.', 'taxocommerce-core-catalog-content-management-for-woocommerce' ) );
        }
        $deleted = wp_delete_post( $id, true );
        if ( ! $deleted || is_wp_error( $deleted ) ) {
            self::redirect( $id, 'error', __( 'No se pudo eliminar el menú.', 'taxocommerce-core-catalog-content-management-for-woocommerce' ) );
        }
        self::redirect( 0, 'ok', __( 'Menú eliminado correctamente.', 'taxocommerce-core-catalog-content-management-for-woocommerce' ) );
    }

    /**
     * List header parts with exactly one Navigation block, including theme-file
     * parts. WordPress can create a DB customization without touching the theme.
     */
    private static function editable_header_parts() {
        if ( ! function_exists( 'get_block_templates' ) ) { return array(); }
        $parts = get_block_templates( array( 'area' => 'header' ), 'wp_template_part' );
        $targets = array();
        foreach ( is_array( $parts ) ? $parts : array() as $part ) {
            if ( empty( $part->id ) || empty( $part->slug ) || (string) ( $part->theme ?? '' ) !== get_stylesheet() ) {
                continue;
            }
            $id = absint( $part->wp_id ?? 0 );
            if ( $id && ! current_user_can( 'edit_post', $id ) ) { continue; }
            $count = 0;
            self::count_navigation_blocks( parse_blocks( (string) $part->content ), $count );
            if ( 1 === $count ) {
                $targets[] = array( 'id' => (string) $part->id, 'title' => (string) $part->title );
            }
        }
        return $targets;
    }

    private static function count_navigation_blocks( $blocks, &$count ) {
        foreach ( $blocks as $block ) {
            if ( 'core/navigation' === ( $block['blockName'] ?? '' ) ) { ++$count; }
            if ( ! empty( $block['innerBlocks'] ) ) {
                self::count_navigation_blocks( $block['innerBlocks'], $count );
            }
        }
    }

    private static function set_reference( &$blocks, $nav_id ) {
        foreach ( $blocks as &$block ) {
            if ( 'core/navigation' === ( $block['blockName'] ?? '' ) ) {
                $block['attrs'] = is_array( $block['attrs'] ?? null ) ? $block['attrs'] : array();
                $block['attrs']['ref'] = $nav_id;
                $block['innerBlocks'] = array();
                $block['innerHTML'] = '';
                $block['innerContent'] = array();
                return true;
            }
            if ( ! empty( $block['innerBlocks'] ) && self::set_reference( $block['innerBlocks'], $nav_id ) ) {
                return true;
            }
        }
        unset( $block );
        return false;
    }

    public static function create() {
        if ( ! current_user_can( 'edit_theme_options' ) ) {
            wp_die( esc_html__( 'No tienes permisos para gestionar este menú.', 'taxocommerce-core-catalog-content-management-for-woocommerce' ) );
        }
        check_admin_referer( self::CREATE );
        $name = isset( $_POST['menu_name'] ) && is_string( $_POST['menu_name'] )
            ? trim( sanitize_text_field( wp_unslash( $_POST['menu_name'] ) ) ) : '';
        if ( '' === $name || strlen( $name ) > 200 ) {
            self::redirect( 0, 'error', __( 'Indica un nombre de menú válido.', 'taxocommerce-core-catalog-content-management-for-woocommerce' ) );
        }
        $existing = get_posts( array(
            'post_type' => 'wp_navigation', 'post_status' => 'any', 'title' => $name,
            'posts_per_page' => 1, 'fields' => 'ids',
        ) );
        if ( $existing ) {
            self::redirect( 0, 'error', __( 'Ya existe otro menú con ese nombre.', 'taxocommerce-core-catalog-content-management-for-woocommerce' ) );
        }
        $id = wp_insert_post( array(
            'post_type' => 'wp_navigation',
            'post_status' => 'publish',
            'post_title' => $name,
            'post_content' => '',
        ), true );
        if ( is_wp_error( $id ) || ! $id ) {
            self::redirect( 0, 'error', is_wp_error( $id ) ? $id->get_error_message() : __( 'No se pudo guardar el menú.', 'taxocommerce-core-catalog-content-management-for-woocommerce' ) );
        }
        self::redirect( $id, 'ok', __( 'Menú nuevo creado. Puedes editarlo sin modificar los existentes.', 'taxocommerce-core-catalog-content-management-for-woocommerce' ) );
    }

    /**
     * Convert validated native WordPress objects to Gutenberg Navigation Link
     * blocks. Data and permissions belong to this WordPress, not TaxoCommerce.
     */
    private static function object_blocks( $type, $ids ) {
        $allowed = array( 'page', 'post', 'category' );
        if ( taxonomy_exists( 'product_cat' ) ) { $allowed[] = 'product_cat'; }
        if ( ! in_array( $type, $allowed, true ) || ! is_array( $ids ) ) {
            return new WP_Error( 'block_nav_type', __( 'Tipo de elemento no permitido.', 'taxocommerce-core-catalog-content-management-for-woocommerce' ) );
        }
        $ids = array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );
        if ( ! $ids || count( $ids ) > 50 ) {
            return new WP_Error( 'block_nav_count', __( 'Selecciona entre 1 y 50 elementos.', 'taxocommerce-core-catalog-content-management-for-woocommerce' ) );
        }
        $result = array();
        foreach ( $ids as $id ) {
            if ( in_array( $type, array( 'page', 'post' ), true ) ) {
                $post = get_post( $id );
                if ( ! $post || $post->post_type !== $type || 'publish' !== $post->post_status || ! empty( $post->post_password ) ) {
                    return new WP_Error( 'block_nav_post', __( 'La selección contiene un contenido no publicado o no válido.', 'taxocommerce-core-catalog-content-management-for-woocommerce' ) );
                }
                $label = get_the_title( $post );
                $url = get_permalink( $id );
                $kind = 'post-type';
            } else {
                $term = get_term( $id, $type );
                if ( ! $term || is_wp_error( $term ) || (string) $term->taxonomy !== $type ) {
                    return new WP_Error( 'block_nav_term', __( 'La categoría seleccionada no existe.', 'taxocommerce-core-catalog-content-management-for-woocommerce' ) );
                }
                $label = $term->name;
                $url = get_term_link( $term, $type );
                $kind = 'taxonomy';
            }
            if ( is_wp_error( $url ) || ! is_string( $url ) ) {
                return new WP_Error( 'block_nav_url', __( 'La URL del enlace no es válida.', 'taxocommerce-core-catalog-content-management-for-woocommerce' ) );
            }
            $url = esc_url_raw( $url, array( 'https', 'http' ) );
            if ( '' === $url || '' === trim( (string) $label ) ) {
                return new WP_Error( 'block_nav_url', __( 'La URL del enlace no es válida.', 'taxocommerce-core-catalog-content-management-for-woocommerce' ) );
            }
            $result[] = array(
                'blockName' => 'core/navigation-link',
                'attrs' => array(
                    'label' => sanitize_text_field( $label ),
                    'url' => $url, 'kind' => $kind, 'type' => $type, 'id' => $id,
                ),
                'innerBlocks' => array(), 'innerHTML' => '', 'innerContent' => array(),
            );
        }
        return $result;
    }

    /** Add selected pages/posts/categories while preserving existing blocks. */
    public static function add_objects() {
        if ( ! current_user_can( 'edit_theme_options' ) ) {
            wp_die( esc_html__( 'No tienes permisos para gestionar este menú.', 'taxocommerce-core-catalog-content-management-for-woocommerce' ) );
        }
        $id = isset( $_POST['tc_nav_id'] ) && is_scalar( $_POST['tc_nav_id'] ) ? absint( $_POST['tc_nav_id'] ) : 0;
        check_admin_referer( self::ADD_OBJECTS . '_' . $id );
        $post = $id ? get_post( $id ) : false;
        if ( ! $post || 'wp_navigation' !== $post->post_type || 'publish' !== $post->post_status || ! current_user_can( 'edit_post', $id ) ) {
            self::redirect( $id, 'error', __( 'La navegación seleccionada no es editable.', 'taxocommerce-core-catalog-content-management-for-woocommerce' ) );
        }
        $hash = isset( $_POST['content_hash'] ) && is_scalar( $_POST['content_hash'] )
            ? sanitize_text_field( wp_unslash( $_POST['content_hash'] ) ) : '';
        if ( ! hash_equals( hash( 'sha256', $post->post_content ), $hash ) ) {
            self::redirect( $id, 'error', __( 'La navegación ha cambiado. Recarga la página antes de guardar.', 'taxocommerce-core-catalog-content-management-for-woocommerce' ) );
        }
        $type = isset( $_POST['object_type'] ) && is_string( $_POST['object_type'] )
            ? sanitize_key( wp_unslash( $_POST['object_type'] ) ) : '';
        $ids = isset( $_POST['object_ids'] ) && is_array( $_POST['object_ids'] )
            ? array_map( 'absint', wp_unslash( $_POST['object_ids'] ) ) : array();
        $new_blocks = self::object_blocks( $type, $ids );
        if ( is_wp_error( $new_blocks ) ) { self::redirect( $id, 'error', $new_blocks->get_error_message() ); }
        $blocks = array_merge( parse_blocks( $post->post_content ), $new_blocks );
        $saved = wp_update_post( array( 'ID' => $id, 'post_content' => wp_slash( serialize_blocks( $blocks ) ) ), true );
        if ( is_wp_error( $saved ) || ! $saved ) {
            self::redirect( $id, 'error', is_wp_error( $saved ) ? $saved->get_error_message() : __( 'No se pudo guardar el menú.', 'taxocommerce-core-catalog-content-management-for-woocommerce' ) );
        }
        self::redirect( $id, 'ok', __( 'Elementos añadidos al menú.', 'taxocommerce-core-catalog-content-management-for-woocommerce' ) );
    }

    /**
     * Remove only a leaf Navigation Link. Never delete an entire submenu or
     * unsupported block implicitly. Keep innerContent placeholders aligned.
     */
    private static function remove_link_block( &$blocks, $wanted, &$counter, &$removed_position ) {
        for ( $pos = 0; $pos < count( $blocks ); ++$pos ) {
            $name = $blocks[ $pos ]['blockName'] ?? '';
            if ( in_array( $name, array( 'core/navigation-link', 'core/navigation-submenu' ), true ) ) {
                if ( $counter === $wanted ) {
                    if ( 'core/navigation-link' !== $name || ! empty( $blocks[ $pos ]['innerBlocks'] )
                        || '' !== trim( (string) ( $blocks[ $pos ]['innerHTML'] ?? '' ) ) ) {
                        return new WP_Error( 'block_nav_protected', __( 'Este bloque requiere el Editor del sitio para cambios avanzados.', 'taxocommerce-core-catalog-content-management-for-woocommerce' ) );
                    }
                    array_splice( $blocks, $pos, 1 );
                    $removed_position = $pos;
                    return true;
                }
                ++$counter;
            }
            if ( ! empty( $blocks[ $pos ]['innerBlocks'] ) ) {
                $child_pos = null;
                $removed = self::remove_link_block( $blocks[ $pos ]['innerBlocks'], $wanted, $counter, $child_pos );
                if ( is_wp_error( $removed ) ) { return $removed; }
                if ( true === $removed ) {
                    if ( null !== $child_pos ) {
                        $fragments = $blocks[ $pos ]['innerContent'] ?? null;
                        if ( ! is_array( $fragments ) ) {
                            return new WP_Error( 'block_nav_content', __( 'La navegación ha cambiado. Recarga la página antes de guardar.', 'taxocommerce-core-catalog-content-management-for-woocommerce' ) );
                        }
                        $child_counter = 0; $content_index = null;
                        foreach ( $fragments as $index => $fragment ) {
                            if ( null === $fragment ) {
                                if ( $child_counter === $child_pos ) { $content_index = $index; break; }
                                ++$child_counter;
                            }
                        }
                        if ( null === $content_index ) {
                            return new WP_Error( 'block_nav_content', __( 'La navegación ha cambiado. Recarga la página antes de guardar.', 'taxocommerce-core-catalog-content-management-for-woocommerce' ) );
                        }
                        array_splice( $blocks[ $pos ]['innerContent'], $content_index, 1 );
                    }
                    return true;
                }
            }
        }
        return false;
    }

    public static function remove_link() {
        if ( ! current_user_can( 'edit_theme_options' ) ) {
            wp_die( esc_html__( 'No tienes permisos para gestionar este menú.', 'taxocommerce-core-catalog-content-management-for-woocommerce' ) );
        }
        $id = isset( $_POST['tc_nav_id'] ) && is_scalar( $_POST['tc_nav_id'] ) ? absint( $_POST['tc_nav_id'] ) : 0;
        check_admin_referer( self::REMOVE . '_' . $id );
        $post = $id ? get_post( $id ) : false;
        if ( ! $post || 'wp_navigation' !== $post->post_type || 'publish' !== $post->post_status || ! current_user_can( 'edit_post', $id ) ) {
            self::redirect( $id, 'error', __( 'La navegación seleccionada no es editable.', 'taxocommerce-core-catalog-content-management-for-woocommerce' ) );
        }
        $hash = isset( $_POST['content_hash'] ) && is_scalar( $_POST['content_hash'] )
            ? sanitize_text_field( wp_unslash( $_POST['content_hash'] ) ) : '';
        if ( ! hash_equals( hash( 'sha256', $post->post_content ), $hash ) ) {
            self::redirect( $id, 'error', __( 'La navegación ha cambiado. Recarga la página antes de guardar.', 'taxocommerce-core-catalog-content-management-for-woocommerce' ) );
        }
        $wanted = isset( $_POST['item_index'] ) && is_scalar( $_POST['item_index'] ) ? absint( $_POST['item_index'] ) : -1;
        $blocks = parse_blocks( $post->post_content );
        $counter = 0; $position = null;
        $result = self::remove_link_block( $blocks, $wanted, $counter, $position );
        if ( is_wp_error( $result ) ) { self::redirect( $id, 'error', $result->get_error_message() ); }
        if ( true !== $result ) { self::redirect( $id, 'error', __( 'El elemento seleccionado ya no existe.', 'taxocommerce-core-catalog-content-management-for-woocommerce' ) ); }
        $saved = wp_update_post( array( 'ID' => $id, 'post_content' => wp_slash( serialize_blocks( $blocks ) ) ), true );
        if ( is_wp_error( $saved ) || ! $saved ) {
            self::redirect( $id, 'error', is_wp_error( $saved ) ? $saved->get_error_message() : __( 'No se pudo guardar el menú.', 'taxocommerce-core-catalog-content-management-for-woocommerce' ) );
        }
        self::redirect( $id, 'ok', __( 'Elemento eliminado del menú.', 'taxocommerce-core-catalog-content-management-for-woocommerce' ) );
    }

    /** Add an explicitly requested link without rewriting existing navigation blocks. */
    public static function add_link() {
        if ( ! current_user_can( 'edit_theme_options' ) ) {
            wp_die( esc_html__( 'No tienes permisos para gestionar este menú.', 'taxocommerce-core-catalog-content-management-for-woocommerce' ) );
        }
        $id = isset( $_POST['tc_nav_id'] ) ? absint( $_POST['tc_nav_id'] ) : 0;
        check_admin_referer( self::ADD_LINK . '_' . $id );
        $post = $id ? get_post( $id ) : false;
        if ( ! $post || 'wp_navigation' !== $post->post_type || ! current_user_can( 'edit_post', $id ) ) {
            self::redirect( $id, 'error', __( 'La navegación seleccionada no es editable.', 'taxocommerce-core-catalog-content-management-for-woocommerce' ) );
        }
        $hash = isset( $_POST['content_hash'] ) && is_scalar( $_POST['content_hash'] )
            ? sanitize_text_field( wp_unslash( (string) $_POST['content_hash'] ) ) : '';
        if ( ! hash_equals( hash( 'sha256', $post->post_content ), $hash ) ) {
            self::redirect( $id, 'error', __( 'La navegación ha cambiado. Recarga la página antes de guardar.', 'taxocommerce-core-catalog-content-management-for-woocommerce' ) );
        }
        $label = isset( $_POST['item_title'] ) && is_string( $_POST['item_title'] )
            ? trim( sanitize_text_field( wp_unslash( $_POST['item_title'] ) ) ) : '';
        $url = isset( $_POST['item_url'] ) && is_string( $_POST['item_url'] )
            ? esc_url_raw( wp_unslash( $_POST['item_url'] ), array( 'https', 'http' ) ) : '';
        if ( '' === $label || strlen( $label ) > 200 || '' === $url ) {
            self::redirect( $id, 'error', __( 'Indica un texto y una URL válidos.', 'taxocommerce-core-catalog-content-management-for-woocommerce' ) );
        }
        $blocks = parse_blocks( $post->post_content );
        $blocks[] = array(
            'blockName' => 'core/navigation-link',
            'attrs' => array( 'label' => $label, 'url' => $url ),
            'innerBlocks' => array(),
            'innerHTML' => '',
            'innerContent' => array(),
        );
        $saved = wp_update_post( array( 'ID' => $id, 'post_content' => wp_slash( serialize_blocks( $blocks ) ) ), true );
        if ( is_wp_error( $saved ) || ! $saved ) {
            self::redirect( $id, 'error', is_wp_error( $saved ) ? $saved->get_error_message() : __( 'No se pudo guardar el menú.', 'taxocommerce-core-catalog-content-management-for-woocommerce' ) );
        }
        self::redirect( $id, 'ok', __( 'Enlace personalizado añadido.', 'taxocommerce-core-catalog-content-management-for-woocommerce' ) );
    }

    public static function activate() {
        if ( ! current_user_can( 'edit_theme_options' ) ) {
            wp_die( esc_html__( 'No tienes permisos para asignar menús al tema.', 'taxocommerce-core-catalog-content-management-for-woocommerce' ) );
        }
        $id = isset( $_POST['tc_nav_id'] ) ? absint( $_POST['tc_nav_id'] ) : 0;
        check_admin_referer( self::ACTIVATE . '_' . $id );
        $nav = $id ? get_post( $id ) : false;
        $header_key = isset( $_POST['tc_header_id'] ) && is_string( $_POST['tc_header_id'] )
            ? sanitize_text_field( wp_unslash( $_POST['tc_header_id'] ) ) : '';
        $targets = wp_list_pluck( self::editable_header_parts(), 'id' );
        if ( ! $nav || 'wp_navigation' !== $nav->post_type || 'publish' !== $nav->post_status
            || ! current_user_can( 'edit_post', $id ) || ! in_array( $header_key, $targets, true ) ) {
            self::redirect( $id, 'error', __( 'La ubicación de menú seleccionada no es válida.', 'taxocommerce-core-catalog-content-management-for-woocommerce' ) );
        }
        $header = get_block_template( $header_key, 'wp_template_part' );
        if ( ! $header || (string) ( $header->theme ?? '' ) !== get_stylesheet() ) {
            self::redirect( $id, 'error', __( 'La ubicación de menú seleccionada no es válida.', 'taxocommerce-core-catalog-content-management-for-woocommerce' ) );
        }
        $blocks = parse_blocks( (string) $header->content );
        $count = 0;
        self::count_navigation_blocks( $blocks, $count );
        if ( 1 !== $count || ! self::set_reference( $blocks, $id ) ) {
            self::redirect( $id, 'error', __( 'La ubicación de menú seleccionada no es válida.', 'taxocommerce-core-catalog-content-management-for-woocommerce' ) );
        }
        $content = serialize_blocks( $blocks );
        $post_id = absint( $header->wp_id ?? 0 );
        if ( $post_id ) {
            if ( ! current_user_can( 'edit_post', $post_id ) || 'wp_template_part' !== get_post_type( $post_id ) ) {
                self::redirect( $id, 'error', __( 'No tienes permisos para asignar menús al tema.', 'taxocommerce-core-catalog-content-management-for-woocommerce' ) );
            }
            $updated = wp_update_post( array( 'ID' => $post_id, 'post_content' => wp_slash( $content ) ), true );
        } else {
            // Match WP_REST_Templates_Controller: a theme-file header is
            // customized through WordPress posts, never by writing theme files.
            $updated = wp_insert_post( wp_slash( array(
                'post_type' => 'wp_template_part',
                'post_status' => 'publish',
                'post_name' => (string) $header->slug,
                'post_title' => (string) $header->title,
                'post_content' => $content,
                'tax_input' => array(
                    'wp_theme' => (string) $header->theme,
                    'wp_template_part_area' => 'header',
                ),
                'meta_input' => array( 'origin' => (string) ( $header->source ?? 'theme' ) ),
            ) ), true );
        }
        if ( is_wp_error( $updated ) || ! $updated ) {
            self::redirect( $id, 'error', is_wp_error( $updated ) ? $updated->get_error_message() : __( 'No se pudo guardar el menú.', 'taxocommerce-core-catalog-content-management-for-woocommerce' ) );
        }
        self::redirect( $id, 'ok', __( 'Menú activado en la ubicación seleccionada.', 'taxocommerce-core-catalog-content-management-for-woocommerce' ) );
    }

    /** Replace attributes only; preserve nested structure, CSS classes and unsupported blocks. */
    private static function update_links( &$blocks, $submitted, &$index ) {
        foreach ( $blocks as &$block ) {
            $name = $block['blockName'] ?? '';
            if ( in_array( $name, array( 'core/navigation-link', 'core/navigation-submenu' ), true ) ) {
                if ( ! isset( $submitted[ $index ] ) || ! is_array( $submitted[ $index ] ) ) {
                    return new WP_Error( 'navigation_fields', __( 'Faltan elementos de la navegación. Recarga la página.', 'taxocommerce-core-catalog-content-management-for-woocommerce' ) );
                }
                $fields = $submitted[ $index ];
                $editable = '' === trim( (string) ( $block['innerHTML'] ?? '' ) );
                if ( $editable ) {
                    $label = isset( $fields['label'] ) && is_scalar( $fields['label'] ) ? sanitize_text_field( $fields['label'] ) : '';
                    if ( '' === $label || strlen( $label ) > 200 ) {
                        return new WP_Error( 'navigation_label', __( 'El texto de un enlace no es válido.', 'taxocommerce-core-catalog-content-management-for-woocommerce' ) );
                    }
                    $block['attrs']['label'] = $label;
                    if ( empty( $block['attrs']['id'] ) ) {
                        $url = isset( $fields['url'] ) && is_scalar( $fields['url'] ) ? esc_url_raw( $fields['url'], array( 'https', 'http', 'mailto', 'tel' ) ) : '';
                        if ( ! $url ) { return new WP_Error( 'navigation_url', __( 'La URL del enlace no es válida.', 'taxocommerce-core-catalog-content-management-for-woocommerce' ) ); }
                        $block['attrs']['url'] = $url;
                    }
                }
                ++$index;
            }
            if ( ! empty( $block['innerBlocks'] ) ) {
                $error = self::update_links( $block['innerBlocks'], $submitted, $index );
                if ( is_wp_error( $error ) ) { return $error; }
            }
        }
        unset( $block );
        return true;
    }

    public static function save() {
        if ( ! current_user_can( 'edit_theme_options' ) ) { wp_die( esc_html__( 'No tienes permisos para editar la navegación.', 'taxocommerce-core-catalog-content-management-for-woocommerce' ) ); }
        $id = isset( $_POST['tc_nav_id'] ) ? absint( $_POST['tc_nav_id'] ) : 0;
        check_admin_referer( self::ACTION . '_' . $id );
        $known = self::header_references();
        $post = $id ? get_post( $id ) : false;
        // A non-header wp_navigation post can also be selected explicitly.
        // Protect it with its WordPress edit capability and content hash.
        if ( ! $post || 'wp_navigation' !== $post->post_type || ! current_user_can( 'edit_post', $id ) ) {
            wp_die( esc_html__( 'La navegación seleccionada no es editable.', 'taxocommerce-core-catalog-content-management-for-woocommerce' ) );
        }
        $hash = isset( $_POST['content_hash'] ) && is_string( $_POST['content_hash'] ) ? sanitize_text_field( wp_unslash( $_POST['content_hash'] ) ) : '';
        if ( ! hash_equals( hash( 'sha256', $post->post_content ), $hash ) ) {
            self::redirect( $id, 'error', __( 'La navegación ha cambiado. Recarga la página antes de guardar.', 'taxocommerce-core-catalog-content-management-for-woocommerce' ) );
        }
        $submitted = isset( $_POST['nav_links'] ) && is_array( $_POST['nav_links'] ) ? map_deep( wp_unslash( $_POST['nav_links'] ), 'sanitize_text_field' ) : array();
        $blocks = parse_blocks( $post->post_content );
        $links = array();
        self::links( $blocks, $links );
        if ( count( $submitted ) !== count( $links ) ) {
            self::redirect( $id, 'error', __( 'El número de enlaces no coincide. Recarga la página.', 'taxocommerce-core-catalog-content-management-for-woocommerce' ) );
        }
        $index = 0;
        $result = self::update_links( $blocks, $submitted, $index );
        if ( is_wp_error( $result ) ) { self::redirect( $id, 'error', $result->get_error_message() ); }
        $new_content = serialize_blocks( $blocks );
        // The current unified form guards concurrent menu title edits too.
        // Legacy forms without this optional field remain supported.
        if ( isset( $_POST['menu_title_hash'] ) ) {
            $title_hash = is_scalar( $_POST['menu_title_hash'] )
                ? sanitize_text_field( wp_unslash( $_POST['menu_title_hash'] ) ) : '';
            if ( ! hash_equals( hash( 'sha256', $post->post_title ), $title_hash ) ) {
                self::redirect( $id, 'error', __( 'La navegación ha cambiado. Recarga la página antes de guardar.', 'taxocommerce-core-catalog-content-management-for-woocommerce' ) );
            }
        }
        // Older Core block-menu forms did not submit the name; retain backward
        // compatibility while enabling the same "Nombre del menú" control.
        $menu_name = isset( $_POST['menu_name'] ) && is_string( $_POST['menu_name'] )
            ? trim( sanitize_text_field( wp_unslash( $_POST['menu_name'] ) ) )
            : (string) $post->post_title;
        if ( '' === $menu_name || strlen( $menu_name ) > 200 ) {
            self::redirect( $id, 'error', __( 'Indica un nombre de menú válido.', 'taxocommerce-core-catalog-content-management-for-woocommerce' ) );
        }
        $result = wp_update_post( array(
            'ID' => $id, 'post_title' => wp_slash( $menu_name ),
            'post_content' => wp_slash( $new_content ),
        ), true );
        if ( is_wp_error( $result ) || ! $result ) {
            self::redirect( $id, 'error', is_wp_error( $result ) ? $result->get_error_message() : __( 'No se pudo guardar el menú.', 'taxocommerce-core-catalog-content-management-for-woocommerce' ) );
        }
        self::redirect( $id, 'ok', __( 'Navegación de bloques guardada en WordPress.', 'taxocommerce-core-catalog-content-management-for-woocommerce' ) );
    }

    private static function redirect( $id, $result, $message ) {
        wp_safe_redirect( add_query_arg( array(
            'page' => 'taxocommerce-core-menu',
            'tc_tab' => 'wordpress',
            'tc_nav_id' => $id,
            'tc_result' => $result,
            'tc_message' => $message,
        ), admin_url( 'admin.php' ) ) );
        exit;
    }
}
