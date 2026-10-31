<?php
/**
 * Unified admin surface for the existing Templates tools.
 *
 * Keeps the previous handlers, associations and editor intact. Old
 * ?tab=requests|roles|editor|composer URLs expand their matching section.
 *
 * @package TaxoCommerceTemplates
 */
defined( 'ABSPATH' ) || exit;

final class TCT_Unified_Manager {
    public static function sections() {
        return array(
            'composer' => array(
                'label' => '1. Diseñar plantilla',
                'help'  => 'Compositor FRONT: nombre, mercado y módulos para ordenador y móvil.',
            ),
            'roles' => array(
                'label' => '2. Asociar rol y archivo',
                'help'  => 'Plantillas registradas, archivo principal, variantes y páginas asociadas.',
            ),
            'editor' => array(
                'label' => '3. Biblioteca y archivos PHP',
                'help'  => 'Consultar, crear, duplicar, descargar y editar archivos con los permisos habituales.',
            ),
            'requests' => array(
                'label' => 'Opciones avanzadas: rutas WordPress y WooCommerce',
                'help'  => 'Asociaciones técnicas por tipo de petición. No son roles SEO y se conservan sin cambios.',
            ),
        );
    }

    public static function render( $focus = 'manager' ) {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Acceso denegado.', 'taxocommerce-templates' ) );
        }
        $sections = self::sections();
        if ( ! isset( $sections[ $focus ] ) ) {
            $focus = 'composer';
        }
        $front_file = '';
        if ( TaxoCommerce_Templates::installed() ) {
            foreach ( TCT_Router::rows() as $row ) {
                if ( 'front_page' === $row->template_key ) {
                    $front_file = (string) $row->template_file;
                    break;
                }
            }
        }
        echo '<div class="wrap tct-unified-manager">';
        echo '<p class="description">Crea la composición visual y consulta aquí mismo sus roles, asignaciones y archivos. Los cambios guardados en el compositor FRONT siguen siendo borradores y no modifican la portada pública.</p>';

        if ( '' !== $front_file ) {
            echo '<div class="tct-manager-current">';
            echo '<strong>Plantilla FRONT existente</strong> · Rol <code>front_page</code> · Archivo <code>' . esc_html( $front_file ) . '</code>';
            echo ' · <a href="' . esc_url( TCT_Request_Admin::admin_url( 'editor', array( 'tct_source' => $front_file ) ) ) . '">Ver archivo</a>';
            echo '</div>';
        }

        foreach ( $sections as $key => $section ) {
            if ( $focus !== $key ) {
                // Deliberately lazy: do not execute the potentially expensive
                // SQL/page inventories or load PHP code-editor content when the
                // administrator is working in another section.
                echo '<a class="tct-manager-section tct-manager-section-link" id="tct-manager-' .
                    esc_attr( $key ) . '" href="' . esc_url( TCT_Request_Admin::admin_url( $key ) ) . '">';
                echo '<span class="tct-manager-section-title">' . esc_html( $section['label'] ) . '</span>';
                echo '<span class="tct-manager-section-help">' . esc_html( $section['help'] ) . '</span>';
                echo '</a>';
                continue;
            }
            echo '<details id="tct-manager-' . esc_attr( $key ) . '" class="tct-manager-section" open>';
            echo '<summary><span class="tct-manager-section-title">' . esc_html( $section['label'] ) . '</span><span class="tct-manager-section-help">' . esc_html( $section['help'] ) . '</span></summary>';
            echo '<div class="tct-manager-section-content">';
            switch ( $key ) {
                case 'composer':
                    TCT_Front_Composer::render( true );
                    break;
                case 'roles':
                    TaxoCommerce_Templates::render_roles_screen( true );
                    break;
                case 'editor':
                    TCT_Template_Editor::render( true );
                    break;
                case 'requests':
                    TCT_Request_Admin::render( true );
                    break;
            }
            echo '</div></details>';
        }
        echo '</div>';
    }
}
