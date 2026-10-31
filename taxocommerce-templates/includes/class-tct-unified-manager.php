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
                'label' => 'Diseñar plantilla por módulos',
                'help'  => 'Compositor FRONT: nombre, mercado, móvil y ordenador; las composiciones se guardan como borradores.',
            ),
            'editor' => array(
                'label' => 'Biblioteca y archivos PHP',
                'help'  => 'Crear, duplicar, descargar o editar archivos clásicos de plantilla.',
            ),
        );
    }

    public static function render( $focus = 'manager' ) {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Acceso denegado.', 'taxocommerce-templates' ) );
        }
        // Route-specific Edit buttons always open that exact saved design.
        $design_id = isset( $_GET['tct_design'] ) && is_string( $_GET['tct_design'] )
            ? sanitize_text_field( wp_unslash( $_GET['tct_design'] ) ) : '';
        if ( '' !== $design_id ) {
            TCT_Designs::render( $design_id );
            return;
        }
        $sections = self::sections();
        if ( ! isset( $sections[ $focus ] ) ) {
            // A routing row can open the designer with the selected PHP file.
            $focus = ( isset( $_GET['tct_source'] ) || isset( $_GET['tct_assign'] ) ) ? 'editor' : 'composer';
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
        echo '<p class="description">Diseña una composición FRONT o trabaja con sus archivos PHP. Para decidir qué rol o petición utiliza cada plantilla, utiliza <a href="' . esc_url( TCT_Request_Admin::admin_url( 'routing' ) ) . '">Enrutamiento</a>.</p>';

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
                case 'editor':
                    TCT_Template_Editor::render( true );
                    break;
            }
            echo '</div></details>';
        }
        echo '</div>';
    }
}
