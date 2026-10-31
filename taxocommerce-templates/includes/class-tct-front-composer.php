<?php
/**
 * FRONT visual composition pilot. Saves an isolated draft; never changes
 * template_include, the front-page assignment or the public storefront.
 *
 * @package TaxoCommerceTemplates
 */
defined( 'ABSPATH' ) || exit;

final class TCT_Front_Composer {
    const OPTION = 'tct_front_composer_drafts_v1';
    const MARKETS = array( 'ES', 'US', 'DE', 'NL', 'FR' );

    public static function catalog() {
        return array(
            'header' => 'Cabecera',
            'menu' => 'Menú del mercado',
            'breadcrumbs' => 'Migas de pan',
            'campaigns' => 'Ofertas y campañas',
            'hero' => 'Bloque principal',
            'benefits' => 'Ventajas del servicio',
            'popular' => 'Lo más visitado',
            'clusters' => 'Clústeres',
            'hubs' => 'Hubs principales',
            'categories' => 'Categorías',
            'products' => 'Productos',
            'posts' => 'Artículos',
            'content' => 'Contenido editorial',
            'footer' => 'Pie de página',
        );
    }

    public static function defaults( $market = 'ES' ) {
        $markets = array(
            'ES' => 'España', 'US' => 'Estados Unidos',
            'DE' => 'Alemania', 'NL' => 'Países Bajos', 'FR' => 'Francia',
        );
        $market = isset( $markets[ $market ] ) ? $market : 'ES';
        $es = array( 'header', 'menu', 'campaigns', 'hero', 'benefits', 'popular', 'clusters', 'hubs', 'categories', 'products', 'posts', 'footer' );
        $intl = array( 'header', 'menu', 'breadcrumbs', 'hero', 'clusters', 'hubs', 'categories', 'products', 'footer' );
        $ids = 'ES' === $market ? $es : $intl;
        $layout = array();
        foreach ( $ids as $id ) {
            $layout[] = array( 'id' => $id, 'enabled' => true, 'title' => '' );
        }
        return array(
            'name' => 'FRONT · ' . $markets[ $market ],
            'role' => 'front_page',
            'market' => $market,
            'desktop' => $layout,
            'mobile' => $layout,
        );
    }

    /** Validate complete module lists; unregistered/duplicated modules are discarded. */
    public static function normalize_layout( $input ) {
        if ( ! is_array( $input ) ) {
            return array();
        }
        $allowed = self::catalog();
        $clean = array();
        $seen = array();
        foreach ( array_slice( $input, 0, count( $allowed ) ) as $item ) {
            if ( ! is_array( $item ) || ! isset( $item['id'] ) || ! is_string( $item['id'] ) ) {
                continue;
            }
            $id = $item['id'];
            if ( ! isset( $allowed[ $id ] ) || isset( $seen[ $id ] ) ) {
                continue;
            }
            $seen[ $id ] = true;
            $title = isset( $item['title'] ) && is_string( $item['title'] )
                ? substr( sanitize_text_field( $item['title'] ), 0, 100 ) : '';
            $clean[] = array(
                'id' => $id,
                'enabled' => isset( $item['enabled'] ) && ( true === $item['enabled'] || 1 === $item['enabled'] || '1' === $item['enabled'] ),
                'title' => $title,
            );
        }
        return $clean;
    }

    public static function market( $value ) {
        return is_string( $value ) && in_array( strtoupper( $value ), self::MARKETS, true )
            ? strtoupper( $value ) : 'ES';
    }

    public static function all() {
        $stored = get_option( self::OPTION, array() );
        return is_array( $stored ) ? $stored : array();
    }

    public static function config( $market ) {
        $market = self::market( $market );
        $stored = self::all();
        $defaults = self::defaults( $market );
        if ( ! isset( $stored[ $market ] ) || ! is_array( $stored[ $market ] ) ) {
            return $defaults;
        }
        $entry = $stored[ $market ];
        $defaults['name'] = isset( $entry['name'] ) && is_string( $entry['name'] )
            ? substr( sanitize_text_field( $entry['name'] ), 0, 100 ) : $defaults['name'];
        foreach ( array( 'desktop', 'mobile' ) as $device ) {
            if ( isset( $entry[ $device ] ) && is_array( $entry[ $device ] ) ) {
                $defaults[ $device ] = self::normalize_layout( $entry[ $device ] );
            }
        }
        return $defaults;
    }

    public static function save() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'No tienes permisos para guardar plantillas.', 'taxocommerce-templates' ) );
        }
        check_admin_referer( 'tct_front_composer_save' );
        $market = isset( $_POST['market'] ) && is_string( $_POST['market'] )
            ? self::market( sanitize_text_field( wp_unslash( $_POST['market'] ) ) ) : 'ES';
        $name = isset( $_POST['template_name'] ) && is_string( $_POST['template_name'] )
            ? substr( sanitize_text_field( wp_unslash( $_POST['template_name'] ) ), 0, 100 ) : '';
        $input = array();
        foreach ( array( 'desktop', 'mobile' ) as $device ) {
            $key = 'layout_' . $device;
            $raw = isset( $_POST[ $key ] ) && is_string( $_POST[ $key ] )
                ? wp_unslash( $_POST[ $key ] ) : '';
            $data = strlen( $raw ) <= 20000 ? json_decode( $raw, true ) : null;
            if ( ! is_array( $data ) || json_last_error() !== JSON_ERROR_NONE ) {
                self::redirect( $market, 'Composición inválida. No se ha guardado.' );
            }
            $input[ $device ] = self::normalize_layout( $data );
        }
        if ( '' === $name ) {
            self::redirect( $market, 'Introduce un nombre para la plantilla.' );
        }
        $all = self::all();
        $all[ $market ] = array(
            'name' => $name, 'role' => 'front_page', 'market' => $market,
            'desktop' => $input['desktop'], 'mobile' => $input['mobile'],
        );
        update_option( self::OPTION, $all, false );
        self::redirect( $market, 'Borrador FRONT guardado. No modifica la portada pública.' );
    }

    private static function redirect( $market, $message ) {
        wp_safe_redirect( TCT_Request_Admin::admin_url( 'composer', array(
            'market' => $market, 'tct_notice' => $message,
        ) ) );
        exit;
    }

    public static function enqueue( $hook ) {
        if ( ! current_user_can( 'manage_options' ) || ! isset( $_GET['page'] )
            || ! is_string( $_GET['page'] ) || 'taxocommerce-templates' !== $_GET['page'] ) {
            return;
        }
        // This tab is the only caller of the pilot's scripts and styles.
        $tab = isset( $_GET['tab'] ) && is_string( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';
        if ( 'composer' !== $tab ) {
            return;
        }
        wp_enqueue_style( 'tct-front-composer', TCT_URL . 'assets/front-composer.css', array(), TCT_VERSION );
        wp_enqueue_script( 'tct-front-composer', TCT_URL . 'assets/front-composer.js', array( 'jquery', 'jquery-ui-sortable' ), TCT_VERSION, true );
        $configs = array();
        foreach ( self::MARKETS as $market ) {
            $configs[ $market ] = self::config( $market );
        }
        wp_localize_script( 'tct-front-composer', 'TCTFrontComposer', array(
            'catalog' => self::catalog(),
            'configs' => $configs,
            'labels' => array(
                'add' => __( 'Añadir', 'taxocommerce-templates' ),
                'remove' => __( 'Quitar', 'taxocommerce-templates' ),
                'configure' => __( 'Configurar', 'taxocommerce-templates' ),
                'visible' => __( 'Visible', 'taxocommerce-templates' ),
                'title' => __( 'Título opcional del módulo', 'taxocommerce-templates' ),
                'up' => __( 'Subir', 'taxocommerce-templates' ),
                'down' => __( 'Bajar', 'taxocommerce-templates' ),
                'unsaved' => __( 'Hay cambios sin guardar. ¿Cambiar de mercado y descartarlos?', 'taxocommerce-templates' ),
            ),
        ) );
    }

    public static function render() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Acceso denegado.', 'taxocommerce-templates' ) );
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only UI selection.
        $market = isset( $_GET['market'] ) && is_string( $_GET['market'] )
            ? self::market( sanitize_text_field( wp_unslash( $_GET['market'] ) ) ) : 'ES';
        $current = self::config( $market );
        ?>
        <div class="wrap tct-front-composer">
            <h2><?php esc_html_e( 'Compositor de plantillas — FRONT', 'taxocommerce-templates' ); ?></h2>
            <p><?php esc_html_e( 'Prueba funcional del editor. Guarda un borrador por mercado y dispositivo; no sustituye la portada, su PHP ni las asignaciones existentes.', 'taxocommerce-templates' ); ?></p>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="tct-front-composer-form">
                <?php wp_nonce_field( 'tct_front_composer_save' ); ?>
                <input type="hidden" name="action" value="tct_front_composer_save">
                <input type="hidden" name="layout_desktop" id="tct-front-desktop-json">
                <input type="hidden" name="layout_mobile" id="tct-front-mobile-json">
                <div class="tct-front-fields">
                    <label><?php esc_html_e( 'Nombre de la plantilla', 'taxocommerce-templates' ); ?>
                        <input type="text" name="template_name" id="tct-front-name" maxlength="100" required value="<?php echo esc_attr( $current['name'] ); ?>">
                    </label>
                    <label><?php esc_html_e( 'Rol asignado', 'taxocommerce-templates' ); ?>
                        <input type="text" value="FRONT — front_page" readonly>
                    </label>
                    <label><?php esc_html_e( 'Mercado', 'taxocommerce-templates' ); ?>
                        <select name="market" id="tct-front-market">
                            <?php
                            foreach ( array(
                                'ES' => 'España (ES)', 'US' => 'Estados Unidos (US)',
                                'DE' => 'Alemania (DE)', 'NL' => 'Países Bajos (NL)', 'FR' => 'Francia (FR)',
                            ) as $code => $label ) :
                                ?>
                                <option value="<?php echo esc_attr( $code ); ?>" <?php selected( $code, $market ); ?>><?php echo esc_html( $label ); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                </div>
                <div class="tct-front-device-tabs" role="group" aria-label="<?php esc_attr_e( 'Dispositivo', 'taxocommerce-templates' ); ?>">
                    <button class="button button-primary" type="button" data-tct-device="desktop" aria-pressed="true"><?php esc_html_e( 'Ordenador', 'taxocommerce-templates' ); ?></button>
                    <button class="button" type="button" data-tct-device="mobile" aria-pressed="false"><?php esc_html_e( 'Móvil', 'taxocommerce-templates' ); ?></button>
                </div>
                <div class="tct-front-columns">
                    <section class="tct-front-panel" aria-labelledby="tct-front-available-title">
                        <h3 id="tct-front-available-title"><?php esc_html_e( 'Módulos disponibles', 'taxocommerce-templates' ); ?></h3>
                        <p><?php esc_html_e( 'Arrastra o pulsa Añadir.', 'taxocommerce-templates' ); ?></p>
                        <ul id="tct-front-available-list" class="tct-front-list"></ul>
                    </section>
                    <section class="tct-front-panel" aria-labelledby="tct-front-selected-title">
                        <h3 id="tct-front-selected-title"><?php esc_html_e( 'Composición de la plantilla', 'taxocommerce-templates' ); ?></h3>
                        <p><?php esc_html_e( 'Arrastra para ordenar. Las flechas permiten ordenar también con teclado o móvil.', 'taxocommerce-templates' ); ?></p>
                        <ul id="tct-front-selected-list" class="tct-front-list" aria-live="polite"></ul>
                    </section>
                </div>
                <p class="tct-front-notice"><?php esc_html_e( 'Borrador: los cambios de composición no se aplican al frontend hasta completar y validar el renderizador modular.', 'taxocommerce-templates' ); ?></p>
                <?php submit_button( __( 'Guardar borrador FRONT', 'taxocommerce-templates' ) ); ?>
            </form>
        </div>
        <?php
    }
}
