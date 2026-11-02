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

    public static function register_hooks() {
        add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'save' ) );
        add_action( 'admin_post_' . self::CREATE, array( __CLASS__, 'create' ) );
        add_action( 'admin_post_' . self::ACTIVATE, array( __CLASS__, 'activate' ) );
        add_action( 'admin_post_' . self::ADD_LINK, array( __CLASS__, 'add_link' ) );
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
    private static function links( $blocks, &$links ) {
        foreach ( $blocks as $block ) {
            $name = $block['blockName'] ?? '';
            if ( in_array( $name, array( 'core/navigation-link', 'core/navigation-submenu' ), true ) ) {
                $attrs = $block['attrs'] ?? array();
                $links[] = array(
                    'label' => (string) ( $attrs['label'] ?? '' ),
                    'url' => (string) ( $attrs['url'] ?? '' ),
                    'custom' => empty( $attrs['id'] ),
                    'editable' => '' === trim( (string) ( $block['innerHTML'] ?? '' ) ),
                );
            }
            if ( ! empty( $block['innerBlocks'] ) ) { self::links( $block['innerBlocks'], $links ); }
        }
    }

    public static function render() {
        if ( ! function_exists( 'wp_is_block_theme' ) || ! wp_is_block_theme() ) { return; }
        $found = self::header_references();
        $menus = $found['menus'];
        // A Navigation block may use WordPress' fallback without a ref, or an
        // existing wp_navigation post from another theme. Make those posts
        // selectable while clearly distinguishing them from header references.
        $other_menus = get_posts( array(
            'post_type' => 'wp_navigation',
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'orderby' => 'title',
            'order' => 'ASC',
            'suppress_filters' => true,
        ) );
        $other_menus = is_array( $other_menus ) ? $other_menus : array();
        foreach ( $other_menus as $nav ) {
            if ( ! isset( $menus[ $nav->ID ] ) ) {
                $menus[ $nav->ID ] = __( 'Navegación existente (sin referencia de cabecera confirmada)', 'taxocommerce-core' );
            }
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only navigation choice.
        $requested = isset( $_GET['tc_nav_id'] ) ? absint( $_GET['tc_nav_id'] ) : 0;
        // Only a directly referenced header navigation is automatically
        // selected. Other navigation posts require an explicit choice.
        $selected = $requested && isset( $menus[ $requested ] ) ? $requested : ( count( $found['menus'] ) === 1 ? (int) key( $found['menus'] ) : ( count( $menus ) === 1 ? (int) key( $menus ) : 0 ) );
        echo '<section class="tc-menu-controls"><h2>' . esc_html__( 'Navegación de la cabecera (tema de bloques)', 'taxocommerce-core' ) . '</h2>';
        echo '<p>' . esc_html__( 'Los menús clásicos y los bloques de navegación son sistemas distintos. Para cambiar la cabecera debes editar la navegación referenciada por su plantilla.', 'taxocommerce-core' ) . '</p>';
        if ( $menus ) {
            echo '<form method="get"><input type="hidden" name="page" value="taxocommerce-core-menu"><input type="hidden" name="tc_tab" value="wordpress">';
            echo '<label for="tc-active-block-navigation">' . esc_html__( 'Selecciona una navegación', 'taxocommerce-core' ) . '</label> ';
            echo '<select id="tc-active-block-navigation" name="tc_nav_id"><option value="0">' . esc_html__( 'Selecciona una navegación', 'taxocommerce-core' ) . '</option>';
            foreach ( $menus as $id => $source ) {
                $post = get_post( $id );
                echo '<option value="' . absint( $id ) . '"' . selected( $selected, $id, false ) . '>' . esc_html( $post->post_title . ' (ID ' . $id . ' — ' . $source . ')' ) . '</option>';
            }
            echo '</select> <button class="button" type="submit">' . esc_html__( 'Cargar para editar', 'taxocommerce-core' ) . '</button></form>';
        }
        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
        echo '<input type="hidden" name="action" value="' . esc_attr( self::CREATE ) . '">';
        wp_nonce_field( self::CREATE );
        echo '<label>' . esc_html__( 'Nombre del nuevo menú', 'taxocommerce-core' ) . ' <input type="text" name="menu_name" maxlength="200" required></label> ';
        echo '<button class="button" type="submit">' . esc_html__( 'Crear menú nuevo', 'taxocommerce-core' ) . '</button></form>';
        if ( $found['inline'] ) {
            echo '<p class="notice notice-warning inline">' . esc_html__( 'El tema contiene una navegación integrada directamente en una plantilla. No aparece en los menús clásicos ni se puede guardar como un menú independiente. Para editar exactamente esa navegación, abre el Editor del sitio.', 'taxocommerce-core' ) . '</p>';
        }
        if ( ! $selected ) {
            echo '<p>' . esc_html__( 'No se ha confirmado una única navegación de cabecera editable. Selecciona una navegación de bloques para examinarla, o abre el Editor del sitio si la cabecera utiliza enlaces integrados. No se modificará ningún menú por suposición.', 'taxocommerce-core' ) . '</p>';
        } else {
            $post = get_post( $selected );
            $blocks = parse_blocks( $post->post_content );
            $links = array();
            self::links( $blocks, $links );
            echo '<h3>' . esc_html( $post->post_title ) . '</h3>';
            $edit_link = get_edit_post_link( $selected, 'raw' );
            if ( $edit_link ) {
                echo '<p><a class="button" href="' . esc_url( $edit_link ) . '">' . esc_html__( 'Editar esta navegación en WordPress', 'taxocommerce-core' ) . '</a></p>';
            }
            echo '<p>' . esc_html( isset( $found['menus'][ $selected ] )
                ? __( 'Este contenido está referenciado desde una plantilla de cabecera. Guardarlo modifica esa navegación de bloques, no los menús clásicos.', 'taxocommerce-core' )
                : __( 'Esta navegación existe en WordPress, pero su uso en la cabecera no está confirmado. Comprueba que sus enlaces coinciden con el menú público antes de guardar.', 'taxocommerce-core' ) ) . '</p>';
            if ( $links ) {
                echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
                echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '"><input type="hidden" name="tc_nav_id" value="' . absint( $selected ) . '">';
                echo '<input type="hidden" name="content_hash" value="' . esc_attr( hash( 'sha256', $post->post_content ) ) . '">';
                wp_nonce_field( self::ACTION . '_' . $selected );
                foreach ( $links as $i => $link ) {
                    echo '<p><label>' . esc_html__( 'Texto del enlace', 'taxocommerce-core' ) . ' ';
                    echo '<input type="text" name="nav_links[' . absint( $i ) . '][label]" value="' . esc_attr( $link['label'] ) . '" maxlength="200"' . ( $link['editable'] ? ' required' : ' readonly' ) . '></label> ';
                    if ( $link['custom'] ) {
                        echo '<label>URL <input type="url" name="nav_links[' . absint( $i ) . '][url]" value="' . esc_attr( $link['url'] ) . '"' . ( $link['editable'] ? ' required' : ' readonly' ) . '></label>';
                    }
                    if ( ! $link['editable'] ) {
                        echo ' <span class="description">' . esc_html__( 'Este bloque requiere el Editor del sitio para cambios avanzados.', 'taxocommerce-core' ) . '</span>';
                    }
                    echo '</p>';
                }
                echo '<button class="button button-primary" type="submit">' . esc_html__( 'Guardar navegación de cabecera', 'taxocommerce-core' ) . '</button></form>';
            } else {
                echo '<p>' . esc_html__( 'Esta navegación está vacía o utiliza bloques que deben editarse desde el Editor del sitio.', 'taxocommerce-core' ) . '</p>';
            }
            echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
            echo '<input type="hidden" name="action" value="' . esc_attr( self::ADD_LINK ) . '">';
            echo '<input type="hidden" name="tc_nav_id" value="' . absint( $selected ) . '">';
            echo '<input type="hidden" name="content_hash" value="' . esc_attr( hash( 'sha256', $post->post_content ) ) . '">';
            wp_nonce_field( self::ADD_LINK . '_' . $selected );
            echo '<h4>' . esc_html__( 'Añadir elementos al menú', 'taxocommerce-core' ) . '</h4>';
            echo '<p><label>' . esc_html__( 'Texto del enlace', 'taxocommerce-core' ) . ' <input name="item_title" type="text" maxlength="200" required></label> ';
            echo '<label>URL <input name="item_url" type="url" required></label> ';
            echo '<button class="button" type="submit">' . esc_html__( 'Añadir al menú', 'taxocommerce-core' ) . '</button></p></form>';
            $targets = self::editable_header_parts();
            if ( $targets ) {
                echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
                echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTIVATE ) . '">';
                echo '<input type="hidden" name="tc_nav_id" value="' . absint( $selected ) . '">';
                wp_nonce_field( self::ACTIVATE . '_' . $selected );
                echo '<label>' . esc_html__( 'Ubicación en el tema', 'taxocommerce-core' ) . ' <select name="tc_header_id">';
                foreach ( $targets as $target ) {
                    echo '<option value="' . esc_attr( $target['id'] ) . '">' . esc_html( $target['title'] ) . '</option>';
                }
                echo '</select></label> ';
                echo '<button type="submit" class="button">' . esc_html__( 'Activar en esta ubicación', 'taxocommerce-core' ) . '</button></form>';
                echo '<p class="description">' . esc_html__( 'La activación guarda la cabecera seleccionada como plantilla personalizada de WordPress, sin modificar ficheros del tema. El menú anterior permanece guardado.', 'taxocommerce-core' ) . '</p>';
            } else {
                echo '<p class="description">' . esc_html__( 'La cabecera procede de una plantilla del tema no personalizada o contiene varias navegaciones. Para activarla, usa el Editor del sitio; no se cambiará la plantilla por suposición.', 'taxocommerce-core' ) . '</p>';
            }
        }
        echo '<p><a class="button" href="' . esc_url( admin_url( 'site-editor.php' ) ) . '">' . esc_html__( 'Editar cabecera en el Editor del sitio', 'taxocommerce-core' ) . '</a></p></section>';
        // Classic menus have their own WordPress theme-location editor and
        // are intentionally not offered as a second tab in block themes.
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
            wp_die( esc_html__( 'No tienes permisos para gestionar este menú.', 'taxocommerce-core' ) );
        }
        check_admin_referer( self::CREATE );
        $name = isset( $_POST['menu_name'] ) && is_string( $_POST['menu_name'] )
            ? trim( sanitize_text_field( wp_unslash( $_POST['menu_name'] ) ) ) : '';
        if ( '' === $name || strlen( $name ) > 200 ) {
            self::redirect( 0, 'error', __( 'Indica un nombre de menú válido.', 'taxocommerce-core' ) );
        }
        $existing = get_posts( array(
            'post_type' => 'wp_navigation', 'post_status' => 'any', 'title' => $name,
            'posts_per_page' => 1, 'fields' => 'ids',
        ) );
        if ( $existing ) {
            self::redirect( 0, 'error', __( 'Ya existe otro menú con ese nombre.', 'taxocommerce-core' ) );
        }
        $id = wp_insert_post( array(
            'post_type' => 'wp_navigation',
            'post_status' => 'publish',
            'post_title' => $name,
            'post_content' => '',
        ), true );
        if ( is_wp_error( $id ) || ! $id ) {
            self::redirect( 0, 'error', is_wp_error( $id ) ? $id->get_error_message() : __( 'No se pudo guardar el menú.', 'taxocommerce-core' ) );
        }
        self::redirect( $id, 'ok', __( 'Menú nuevo creado. Puedes editarlo sin modificar los existentes.', 'taxocommerce-core' ) );
    }

    /** Add an explicitly requested link without rewriting existing navigation blocks. */
    public static function add_link() {
        if ( ! current_user_can( 'edit_theme_options' ) ) {
            wp_die( esc_html__( 'No tienes permisos para gestionar este menú.', 'taxocommerce-core' ) );
        }
        $id = isset( $_POST['tc_nav_id'] ) ? absint( $_POST['tc_nav_id'] ) : 0;
        check_admin_referer( self::ADD_LINK . '_' . $id );
        $post = $id ? get_post( $id ) : false;
        if ( ! $post || 'wp_navigation' !== $post->post_type || ! current_user_can( 'edit_post', $id ) ) {
            self::redirect( $id, 'error', __( 'La navegación seleccionada no es editable.', 'taxocommerce-core' ) );
        }
        $hash = isset( $_POST['content_hash'] ) && is_scalar( $_POST['content_hash'] )
            ? sanitize_text_field( wp_unslash( (string) $_POST['content_hash'] ) ) : '';
        if ( ! hash_equals( hash( 'sha256', $post->post_content ), $hash ) ) {
            self::redirect( $id, 'error', __( 'La navegación ha cambiado. Recarga la página antes de guardar.', 'taxocommerce-core' ) );
        }
        $label = isset( $_POST['item_title'] ) && is_string( $_POST['item_title'] )
            ? trim( sanitize_text_field( wp_unslash( $_POST['item_title'] ) ) ) : '';
        $url = isset( $_POST['item_url'] ) && is_string( $_POST['item_url'] )
            ? esc_url_raw( wp_unslash( $_POST['item_url'] ), array( 'https', 'http' ) ) : '';
        if ( '' === $label || strlen( $label ) > 200 || '' === $url ) {
            self::redirect( $id, 'error', __( 'Indica un texto y una URL válidos.', 'taxocommerce-core' ) );
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
            self::redirect( $id, 'error', is_wp_error( $saved ) ? $saved->get_error_message() : __( 'No se pudo guardar el menú.', 'taxocommerce-core' ) );
        }
        self::redirect( $id, 'ok', __( 'Enlace personalizado añadido.', 'taxocommerce-core' ) );
    }

    public static function activate() {
        if ( ! current_user_can( 'edit_theme_options' ) ) {
            wp_die( esc_html__( 'No tienes permisos para asignar menús al tema.', 'taxocommerce-core' ) );
        }
        $id = isset( $_POST['tc_nav_id'] ) ? absint( $_POST['tc_nav_id'] ) : 0;
        check_admin_referer( self::ACTIVATE . '_' . $id );
        $nav = $id ? get_post( $id ) : false;
        $header_key = isset( $_POST['tc_header_id'] ) && is_string( $_POST['tc_header_id'] )
            ? sanitize_text_field( wp_unslash( $_POST['tc_header_id'] ) ) : '';
        $targets = wp_list_pluck( self::editable_header_parts(), 'id' );
        if ( ! $nav || 'wp_navigation' !== $nav->post_type || 'publish' !== $nav->post_status
            || ! current_user_can( 'edit_post', $id ) || ! in_array( $header_key, $targets, true ) ) {
            self::redirect( $id, 'error', __( 'La ubicación de menú seleccionada no es válida.', 'taxocommerce-core' ) );
        }
        $header = get_block_template( $header_key, 'wp_template_part' );
        if ( ! $header || (string) ( $header->theme ?? '' ) !== get_stylesheet() ) {
            self::redirect( $id, 'error', __( 'La ubicación de menú seleccionada no es válida.', 'taxocommerce-core' ) );
        }
        $blocks = parse_blocks( (string) $header->content );
        $count = 0;
        self::count_navigation_blocks( $blocks, $count );
        if ( 1 !== $count || ! self::set_reference( $blocks, $id ) ) {
            self::redirect( $id, 'error', __( 'La ubicación de menú seleccionada no es válida.', 'taxocommerce-core' ) );
        }
        $content = serialize_blocks( $blocks );
        $post_id = absint( $header->wp_id ?? 0 );
        if ( $post_id ) {
            if ( ! current_user_can( 'edit_post', $post_id ) || 'wp_template_part' !== get_post_type( $post_id ) ) {
                self::redirect( $id, 'error', __( 'No tienes permisos para asignar menús al tema.', 'taxocommerce-core' ) );
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
            self::redirect( $id, 'error', is_wp_error( $updated ) ? $updated->get_error_message() : __( 'No se pudo guardar el menú.', 'taxocommerce-core' ) );
        }
        self::redirect( $id, 'ok', __( 'Menú activado en la ubicación seleccionada.', 'taxocommerce-core' ) );
    }

    /** Replace attributes only; preserve nested structure, CSS classes and unsupported blocks. */
    private static function update_links( &$blocks, $submitted, &$index ) {
        foreach ( $blocks as &$block ) {
            $name = $block['blockName'] ?? '';
            if ( in_array( $name, array( 'core/navigation-link', 'core/navigation-submenu' ), true ) ) {
                if ( ! isset( $submitted[ $index ] ) || ! is_array( $submitted[ $index ] ) ) {
                    return new WP_Error( 'navigation_fields', __( 'Faltan elementos de la navegación. Recarga la página.', 'taxocommerce-core' ) );
                }
                $fields = $submitted[ $index ];
                $editable = '' === trim( (string) ( $block['innerHTML'] ?? '' ) );
                if ( $editable ) {
                    $label = isset( $fields['label'] ) && is_scalar( $fields['label'] ) ? sanitize_text_field( $fields['label'] ) : '';
                    if ( '' === $label || strlen( $label ) > 200 ) {
                        return new WP_Error( 'navigation_label', __( 'El texto de un enlace no es válido.', 'taxocommerce-core' ) );
                    }
                    $block['attrs']['label'] = $label;
                    if ( empty( $block['attrs']['id'] ) ) {
                        $url = isset( $fields['url'] ) && is_scalar( $fields['url'] ) ? esc_url_raw( $fields['url'], array( 'https', 'http', 'mailto', 'tel' ) ) : '';
                        if ( ! $url ) { return new WP_Error( 'navigation_url', __( 'La URL del enlace no es válida.', 'taxocommerce-core' ) ); }
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
        if ( ! current_user_can( 'edit_theme_options' ) ) { wp_die( esc_html__( 'No tienes permisos para editar la navegación.', 'taxocommerce-core' ) ); }
        $id = isset( $_POST['tc_nav_id'] ) ? absint( $_POST['tc_nav_id'] ) : 0;
        check_admin_referer( self::ACTION . '_' . $id );
        $known = self::header_references();
        $post = $id ? get_post( $id ) : false;
        // A non-header wp_navigation post can also be selected explicitly.
        // Protect it with its WordPress edit capability and content hash.
        if ( ! $post || 'wp_navigation' !== $post->post_type || ! current_user_can( 'edit_post', $id ) ) {
            wp_die( esc_html__( 'La navegación seleccionada no es editable.', 'taxocommerce-core' ) );
        }
        $hash = isset( $_POST['content_hash'] ) && is_string( $_POST['content_hash'] ) ? sanitize_text_field( wp_unslash( $_POST['content_hash'] ) ) : '';
        if ( ! hash_equals( hash( 'sha256', $post->post_content ), $hash ) ) {
            self::redirect( $id, 'error', __( 'La navegación ha cambiado. Recarga la página antes de guardar.', 'taxocommerce-core' ) );
        }
        $submitted = isset( $_POST['nav_links'] ) && is_array( $_POST['nav_links'] ) ? map_deep( wp_unslash( $_POST['nav_links'] ), 'sanitize_text_field' ) : array();
        $blocks = parse_blocks( $post->post_content );
        $links = array();
        self::links( $blocks, $links );
        if ( ! $links || count( $submitted ) !== count( $links ) ) {
            self::redirect( $id, 'error', __( 'El número de enlaces no coincide. Recarga la página.', 'taxocommerce-core' ) );
        }
        $index = 0;
        $result = self::update_links( $blocks, $submitted, $index );
        if ( is_wp_error( $result ) ) { self::redirect( $id, 'error', $result->get_error_message() ); }
        $new_content = serialize_blocks( $blocks );
        $result = wp_update_post( array( 'ID' => $id, 'post_content' => wp_slash( $new_content ) ), true );
        if ( is_wp_error( $result ) ) { self::redirect( $id, 'error', $result->get_error_message() ); }
        self::redirect( $id, 'ok', __( 'Navegación de bloques guardada en WordPress.', 'taxocommerce-core' ) );
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
