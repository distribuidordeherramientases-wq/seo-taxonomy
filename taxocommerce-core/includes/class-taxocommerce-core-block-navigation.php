<?php
/**
 * Edit a referenced Navigation block without confusing it with a classic nav_menu.
 * Inline template navigation is intentionally left to the Site Editor.
 */
defined( 'ABSPATH' ) || exit;

final class TaxoCommerce_Core_Block_Navigation {
    const ACTION = 'taxocommerce_core_save_block_navigation';

    public static function register_hooks() {
        add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'save' ) );
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

        // Prefer header parts actually referenced by the site's main page templates.
        $templates = get_block_templates( array( 'slug__in' => array( 'front-page', 'home', 'index' ) ), 'wp_template' );
        $used_slugs = array();
        if ( is_array( $templates ) ) {
            $order = array( 'front-page' => 0, 'home' => 1, 'index' => 2 );
            usort( $templates, static function ( $a, $b ) use ( $order ) {
                return ( $order[ $a->slug ] ?? 10 ) - ( $order[ $b->slug ] ?? 10 );
            } );
            foreach ( $templates as $template ) {
                $found = array();
                self::scan( parse_blocks( (string) $template->content ), $found, $inline, $used_slugs );
                if ( $used_slugs || $found ) {
                    foreach ( $found as $id ) { $menus[ $id ] = (string) $template->title; }
                    break;
                }
            }
        }

        foreach ( $parts as $part ) {
            if ( $used_slugs && ! in_array( (string) $part->slug, $used_slugs, true ) ) { continue; }
            $found = array();
            $nested_parts = array();
            self::scan( parse_blocks( (string) $part->content ), $found, $inline, $nested_parts );
            foreach ( $found as $id ) { $menus[ $id ] = (string) $part->title; }
        }

        // When the main templates do not identify a header, report all header parts as candidates.
        if ( ! $menus && ! $used_slugs ) {
            foreach ( $parts as $part ) {
                $found = array();
                $nested = array();
                self::scan( parse_blocks( (string) $part->content ), $found, $inline, $nested );
                foreach ( $found as $id ) { $menus[ $id ] = (string) $part->title; }
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
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only navigation choice.
        $requested = isset( $_GET['tc_nav_id'] ) ? absint( $_GET['tc_nav_id'] ) : 0;
        $selected = $requested && isset( $menus[ $requested ] ) ? $requested : ( count( $menus ) === 1 ? (int) key( $menus ) : 0 );
        echo '<section class="tc-menu-controls"><h2>' . esc_html__( 'Navegación de la cabecera (tema de bloques)', 'taxocommerce-core' ) . '</h2>';
        echo '<p>' . esc_html__( 'Los menús clásicos y los bloques de navegación son sistemas distintos. Para cambiar la cabecera debes editar la navegación referenciada por su plantilla.', 'taxocommerce-core' ) . '</p>';
        if ( $menus ) {
            echo '<form method="get"><input type="hidden" name="page" value="taxocommerce-core-menu"><input type="hidden" name="tc_tab" value="wordpress">';
            echo '<label for="tc-active-block-navigation">' . esc_html__( 'Navegaciones encontradas en las cabeceras', 'taxocommerce-core' ) . '</label> ';
            echo '<select id="tc-active-block-navigation" name="tc_nav_id"><option value="0">' . esc_html__( 'Selecciona una navegación', 'taxocommerce-core' ) . '</option>';
            foreach ( $menus as $id => $source ) {
                $post = get_post( $id );
                echo '<option value="' . absint( $id ) . '"' . selected( $selected, $id, false ) . '>' . esc_html( $post->post_title . ' (ID ' . $id . ' — ' . $source . ')' ) . '</option>';
            }
            echo '</select> <button class="button" type="submit">' . esc_html__( 'Cargar navegación de cabecera', 'taxocommerce-core' ) . '</button></form>';
        }
        if ( $found['inline'] ) {
            echo '<p class="notice notice-warning inline">' . esc_html__( 'Hay enlaces de navegación integrados en la plantilla, sin menú independiente. Se editan en el Editor del sitio.', 'taxocommerce-core' ) . '</p>';
        }
        if ( ! $selected ) {
            echo '<p>' . esc_html__( 'No se ha identificado una única navegación editable. No se modificará ningún menú por suposición.', 'taxocommerce-core' ) . '</p>';
        } else {
            $post = get_post( $selected );
            $blocks = parse_blocks( $post->post_content );
            $links = array();
            self::links( $blocks, $links );
            echo '<h3>' . esc_html( $post->post_title ) . '</h3>';
            echo '<p>' . esc_html__( 'Este contenido está referenciado desde una plantilla de cabecera. Guardarlo modifica esa navegación de bloques, no los menús clásicos.', 'taxocommerce-core' ) . '</p>';
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
                echo '<p>' . esc_html__( 'Esta navegación utiliza bloques que deben editarse desde el Editor del sitio.', 'taxocommerce-core' ) . '</p>';
            }
        }
        echo '<p><a class="button" href="' . esc_url( admin_url( 'site-editor.php' ) ) . '">' . esc_html__( 'Editar cabecera en el Editor del sitio', 'taxocommerce-core' ) . '</a></p></section>';
        echo '<hr><h2>' . esc_html__( 'Menús clásicos (independientes de la navegación de bloques)', 'taxocommerce-core' ) . '</h2>';
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
        $post = $id && isset( $known['menus'][ $id ] ) ? get_post( $id ) : false;
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
