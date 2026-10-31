<?php
/**
 * Independently editable country-aware template designs.
 *
 * WordPress option storage deliberately avoids changing the legacy SEO tables.
 * Existing routes are never migrated or enabled automatically.
 */
defined( 'ABSPATH' ) || exit;

final class TCT_Designs {
    const OPTION = 'tct_template_designs_v1';
    const COUNTRIES = array( 'ES', 'US', 'DE', 'NL', 'FR' );

    public static function all() {
        $value = get_option( self::OPTION, array() );
        return is_array( $value ) ? $value : array();
    }

    public static function get( $id ) {
        $all = self::all();
        return is_string( $id ) && isset( $all[ $id ] ) && is_array( $all[ $id ] )
            ? $all[ $id ] : null;
    }

    public static function countries( $input ) {
        $clean = array();
        foreach ( (array) $input as $code ) {
            if ( is_string( $code ) ) {
                $code = strtoupper( trim( $code ) );
                if ( in_array( $code, self::COUNTRIES, true ) && ! in_array( $code, $clean, true ) ) {
                    $clean[] = $code;
                }
            }
        }
        return $clean;
    }

    public static function default_layout() {
        $layout = array();
        foreach ( array( 'header', 'menu', 'content', 'footer' ) as $module ) {
            $layout[] = array( 'id' => $module, 'enabled' => true, 'title' => '' );
        }
        return $layout;
    }

    public static function config( $design, $country ) {
        $layouts = isset( $design['layouts'] ) && is_array( $design['layouts'] )
            ? $design['layouts'] : array();
        $stored = isset( $layouts[ $country ] ) && is_array( $layouts[ $country ] )
            ? $layouts[ $country ] : array();
        $defaults = self::default_layout();
        return array(
            'name' => (string) $design['name'],
            'desktop' => isset( $stored['desktop'] )
                ? TCT_Front_Composer::normalize_layout( $stored['desktop'] ) : $defaults,
            'mobile' => isset( $stored['mobile'] )
                ? TCT_Front_Composer::normalize_layout( $stored['mobile'] ) : $defaults,
        );
    }

    public static function name_for( $label, $country, $number ) {
        return substr( sanitize_text_field( $label ), 0, 60 ) . ' · ' .
            implode( ', ', self::countries( $country ) ) . ' · ' . (int) $number;
    }

    private static function redirect( $message, $id = '', $country = '' ) {
        $args = array( 'tct_notice' => $message );
        if ( $id ) { $args['tct_design'] = $id; }
        if ( $country ) { $args['market'] = $country; }
        wp_safe_redirect( TCT_Request_Admin::admin_url( $id ? 'designer' : 'routing', $args ) );
        exit;
    }

    public static function create() {
        if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'Acceso denegado.' ); }
        check_admin_referer( 'tct_design_create' );
        $key = isset( $_POST['route_key'] ) && is_string( $_POST['route_key'] )
            ? sanitize_key( wp_unslash( $_POST['route_key'] ) ) : '';
        $items = TCT_Routing_Admin::items();
        if ( ! isset( $items[ $key ] ) ) {
            self::redirect( 'No existe ese tipo de petición o rol.' );
        }
        $all = self::all();
        $file = '';
        if ( TaxoCommerce_Templates::installed() ) {
            foreach ( TCT_Router::rows() as $row ) {
                if ( $key === (string) $row->template_key && TCT_Router::path( $row->template_file ) ) {
                    $file = (string) $row->template_file;
                    break;
                }
            }
        }
        $id = wp_generate_uuid4();
        $all[ $id ] = array(
            'id' => $id,
            'route' => $key,
            'name' => self::name_for( $items[ $key ]['label'], array( 'ES' ), count( $all ) + 1 ),
            'countries' => array( 'ES' ),
            'file' => $file,
            'active' => false,
            'layouts' => array(),
        );
        if ( ! update_option( self::OPTION, $all, false ) ) {
            self::redirect( 'No se pudo crear la plantilla.' );
        }
        self::redirect( 'Plantilla creada. Configúrala antes de activarla.', $id, 'ES' );
    }

    public static function can_activate( $all, $id, $route, $countries, $file ) {
        if ( '' === $file || ! TCT_Router::path( $file ) ) {
            return 'Para activar una ruta necesitas seleccionar un archivo PHP existente. El compositor por módulos continúa siendo un borrador hasta disponer de renderizador.';
        }
        foreach ( $all as $other_id => $other ) {
            if ( $id === $other_id || empty( $other['active'] ) || $route !== (string) $other['route'] ) {
                continue;
            }
            if ( array_intersect( $countries, self::countries( $other['countries'] ) ) ) {
                return 'Conflicto: otra plantilla activa del mismo rol ya está asignada a alguno de estos países.';
            }
        }
        return '';
    }

    public static function save() {
        if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'Acceso denegado.' ); }
        check_admin_referer( 'tct_design_save' );
        $id = isset( $_POST['design_id'] ) && is_string( $_POST['design_id'] )
            ? sanitize_text_field( wp_unslash( $_POST['design_id'] ) ) : '';
        $all = self::all();
        if ( ! isset( $all[ $id ] ) ) { self::redirect( 'Plantilla desconocida.' ); }
        $design = $all[ $id ];
        $name = isset( $_POST['template_name'] ) && is_string( $_POST['template_name'] )
            ? substr( sanitize_text_field( wp_unslash( $_POST['template_name'] ) ), 0, 100 ) : '';
        $raw_countries = isset( $_POST['countries'] ) && is_array( $_POST['countries'] )
            ? array_map( 'sanitize_text_field', wp_unslash( $_POST['countries'] ) ) : array();
        $countries = self::countries( $raw_countries );
        $market = isset( $_POST['market'] ) && is_string( $_POST['market'] )
            ? strtoupper( sanitize_text_field( wp_unslash( $_POST['market'] ) ) ) : '';
        $file = isset( $_POST['template_file'] ) && is_string( $_POST['template_file'] )
            ? sanitize_file_name( wp_unslash( $_POST['template_file'] ) ) : '';
        $active = ! empty( $_POST['is_active'] );
        if ( '' === $name || ! $countries || ! in_array( $market, $countries, true ) ) {
            self::redirect( 'Indica un nombre, selecciona países y edita un país incluido en la plantilla.', $id );
        }
        if ( '' !== $file && ! TCT_Router::path( $file ) ) {
            self::redirect( 'Archivo de plantilla no disponible.', $id, $market );
        }
        if ( $active ) {
            $conflict = self::can_activate( $all, $id, $design['route'], $countries, $file );
            if ( $conflict ) { self::redirect( $conflict, $id, $market ); }
        }
        $layouts = array();
        foreach ( array( 'desktop', 'mobile' ) as $device ) {
            $raw = isset( $_POST['layout_' . $device] ) && is_string( $_POST['layout_' . $device] )
                ? wp_unslash( $_POST['layout_' . $device] ) : '';
            $data = strlen( $raw ) < 20000 ? json_decode( $raw, true ) : null;
            if ( ! is_array( $data ) || json_last_error() !== JSON_ERROR_NONE ) {
                self::redirect( 'La composición no es válida. No se ha guardado.', $id, $market );
            }
            $layouts[ $device ] = TCT_Front_Composer::normalize_layout( $data );
        }
        $design['name'] = $name;
        $design['countries'] = $countries;
        $design['file'] = $file;
        $design['active'] = $active;
        if ( ! isset( $design['layouts'] ) || ! is_array( $design['layouts'] ) ) {
            $design['layouts'] = array();
        }
        $design['layouts'][ $market ] = $layouts;
        $all[ $id ] = $design;
        update_option( self::OPTION, $all, false );
        self::redirect( $active
            ? 'Plantilla guardada y activada. Comprueba su ruta en STAGING.'
            : 'Configuración guardada como borrador, sin modificar la página pública.', $id, $market );
    }

    public static function market_for_request() {
        $id = absint( get_queried_object_id() );
        if ( class_exists( 'TaxoCommerce_Core_Country', false ) ) {
            $market = ( is_category() || is_tag() || is_tax() )
                ? TaxoCommerce_Core_Country::term( $id )
                : TaxoCommerce_Core_Country::post( $id );
            return in_array( $market, self::COUNTRIES, true ) ? $market : 'ES';
        }
        $meta = ( is_category() || is_tag() || is_tax() )
            ? get_term_meta( $id, '_seo_pais_origen', true )
            : get_post_meta( $id, '_seo_pais_origen', true );
        $market = strtoupper( (string) $meta );
        return in_array( $market, self::COUNTRIES, true ) ? $market : 'ES';
    }

    /** The caller already checked the global router switch and pilot priority. */
    public static function resolve( $candidates ) {
        $all = self::all();
        if ( ! $all ) { return ''; }
        $country = self::market_for_request();
        foreach ( $candidates as $key ) {
            $found = array();
            foreach ( $all as $design ) {
                if ( ! empty( $design['active'] ) && $key === $design['route']
                    && in_array( $country, self::countries( $design['countries'] ), true ) ) {
                    $found[] = $design;
                }
            }
            // Never choose arbitrarily between two overlapping active rules.
            if ( count( $found ) > 1 ) { return ''; }
            if ( count( $found ) === 1 ) {
                $file = (string) $found[0]['file'];
                $path = TCT_Router::path( $file );
                if ( $path ) {
                    $GLOBALS['tct_template_route'] = $key;
                    $GLOBALS['tct_template_file'] = basename( $path );
                    $GLOBALS['tct_template_design_id'] = $found[0]['id'];
                    $GLOBALS['tct_template_country'] = $country;
                    return $path;
                }
            }
        }
        return '';
    }

    public static function enqueue() {
        if ( ! current_user_can( 'manage_options' ) || ! isset( $_GET['page'] )
            || 'taxocommerce-templates' !== $_GET['page'] ) { return; }
        $id = isset( $_GET['tct_design'] ) && is_string( $_GET['tct_design'] )
            ? sanitize_text_field( wp_unslash( $_GET['tct_design'] ) ) : '';
        $design = self::get( $id );
        if ( ! $design ) { return; }
        $configs = array();
        foreach ( self::COUNTRIES as $country ) {
            $configs[ $country ] = self::config( $design, $country );
        }
        wp_localize_script( 'tct-front-composer', 'TCTDesignComposer', array(
            'catalog' => TCT_Front_Composer::catalog(),
            'configs' => $configs,
            'labels' => array(
                'add' => 'Añadir', 'remove' => 'Quitar', 'configure' => 'Configurar',
                'visible' => 'Visible', 'title' => 'Título opcional del módulo',
                'up' => 'Subir', 'down' => 'Bajar',
                'unsaved' => 'Los cambios de módulos del país actual sin guardar se perderán. ¿Continuar?',
            ),
        ) );
    }

    public static function render( $id ) {
        $design = self::get( $id );
        if ( ! $design ) {
            echo '<p>La plantilla no existe. <a href="' . esc_url( TCT_Request_Admin::admin_url( 'routing' ) ) . '">Volver al enrutamiento</a>.</p>';
            return;
        }
        $market = isset( $_GET['market'] ) && is_string( $_GET['market'] )
            ? strtoupper( sanitize_text_field( wp_unslash( $_GET['market'] ) ) ) : '';
        if ( ! in_array( $market, self::COUNTRIES, true ) ) { $market = $design['countries'][0]; }
        $items = TCT_Routing_Admin::items( false );
        $label = isset( $items[ $design['route'] ] ) ? $items[ $design['route'] ]['label'] : $design['route'];
        $files = TCT_Router::available_files();
        echo '<div class="wrap tct-front-composer tct-design-editor">';
        echo '<p><a href="' . esc_url( TCT_Request_Admin::admin_url( 'routing' ) ) . '">← Enrutamiento</a></p>';
        echo '<h2>Editar plantilla: ' . esc_html( $design['name'] ) . '</h2>';
        echo '<p>Petición / rol: <strong>' . esc_html( $label ) . '</strong> · <code>' . esc_html( $design['route'] ) . '</code></p>';
        echo '<p class="description">Cada plantilla tiene su propia configuración. Selecciona los países a los que se aplica y configura por separado los módulos de cada país. La presentación modular es un borrador mientras no exista un renderizador público.</p>';
        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" id="tct-front-composer-form">';
        wp_nonce_field( 'tct_design_save' );
        echo '<input type="hidden" name="action" value="tct_design_save">';
        echo '<input type="hidden" name="design_id" value="' . esc_attr( $id ) . '">';
        echo '<input type="hidden" name="layout_desktop" id="tct-front-desktop-json">';
        echo '<input type="hidden" name="layout_mobile" id="tct-front-mobile-json">';
        echo '<div class="tct-front-fields"><label>Nombre de la plantilla';
        echo '<input id="tct-front-name" name="template_name" maxlength="100" required value="' . esc_attr( $design['name'] ) . '"></label>';
        echo '<label>Archivo PHP asociado <select name="template_file">';
        echo '<option value="">Sin archivo (borrador visual)</option>';
        foreach ( $files as $file ) {
            echo '<option value="' . esc_attr( $file ) . '" ' . selected( $file, $design['file'], false ) . '>' . esc_html( $file ) . '</option>';
        }
        echo '</select></label></div>';
        echo '<fieldset class="tct-design-countries"><legend><strong>Países de esta plantilla</strong></legend>';
        foreach ( self::COUNTRIES as $code ) {
            echo '<label><input type="checkbox" name="countries[]" value="' . esc_attr( $code ) . '" ' .
                checked( in_array( $code, $design['countries'], true ), true, false ) . '> ' . esc_html( $code ) . '</label> ';
        }
        echo '</fieldset>';
        echo '<p><label for="tct-front-market"><strong>Editar módulos del país</strong></label> ';
        echo '<select id="tct-front-market" name="market">';
        foreach ( self::COUNTRIES as $code ) {
            echo '<option value="' . esc_attr( $code ) . '" ' . selected( $code, $market, false ) . '>' . esc_html( $code ) . '</option>';
        }
        echo '</select> <span class="description">Guarda antes de cambiar de país para conservar los cambios de esta composición.</span></p>';
        echo '<div class="tct-front-device-tabs" role="group">';
        echo '<button class="button button-primary" type="button" data-tct-device="desktop" aria-pressed="true">Ordenador</button> ';
        echo '<button class="button" type="button" data-tct-device="mobile" aria-pressed="false">Móvil</button></div>';
        echo '<div class="tct-front-columns"><section class="tct-front-panel"><h3>Módulos disponibles</h3><ul id="tct-front-available-list" class="tct-front-list"></ul></section>';
        echo '<section class="tct-front-panel"><h3>Composición de la plantilla</h3><ul id="tct-front-selected-list" class="tct-front-list"></ul></section></div>';
        echo '<p><label><input type="checkbox" name="is_active" value="1" ' .
            checked( ! empty( $design['active'] ), true, false ) . '> Activar esta ruta para los países seleccionados</label></p>';
        echo '<p class="description">Solo se puede activar con archivo PHP existente y sin países solapados con otra plantilla activa del mismo rol. Requiere además el enrutador general habilitado. Los módulos visuales no modifican todavía el PHP.</p>';
        submit_button( 'Guardar configuración de esta plantilla' );
        echo '</form></div>';
    }

    public static function create_form( $route, $label = 'Crear plantilla' ) {
        echo '<form class="tct-design-create" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
        wp_nonce_field( 'tct_design_create' );
        echo '<input type="hidden" name="action" value="tct_design_create">';
        echo '<input type="hidden" name="route_key" value="' . esc_attr( $route ) . '">';
        submit_button( $label, 'secondary', 'submit', false );
        echo '</form>';
    }

    public static function render_routes( $view, $items, $search = '' ) {
        $all = self::all();
        if ( 'pending' === $view || ! $all ) { return; }
        $rows = array();
        foreach ( $all as $id => $design ) {
            $route = (string) $design['route'];
            $item = isset( $items[ $route ] ) ? $items[ $route ] : null;
            if ( ! $item ) { continue; }
            if ( '' !== $search && false === stripos(
                $design['name'] . ' ' . $route . ' ' . implode( ' ', $design['countries'] ), $search
            ) ) { continue; }
            $rows[ $id ] = $design;
        }
        if ( ! $rows ) { return; }
        echo '<h3>Plantillas configuradas (' . count( $rows ) . ')</h3>';
        echo '<table class="widefat striped tct-design-routes"><thead><tr><th>Petición / rol</th><th>Plantilla</th><th>Archivo PHP</th><th>Países</th><th>Estado</th><th>Editor</th></tr></thead><tbody>';
        foreach ( $rows as $id => $design ) {
            $item = $items[ $design['route'] ];
            $link = TCT_Request_Admin::admin_url( 'designer', array( 'tct_design' => $id ) );
            echo '<tr><td><strong>' . esc_html( $item['label'] ) . '</strong><br><code>' . esc_html( $design['route'] ) . '</code></td>';
            echo '<td>' . esc_html( $design['name'] ) . '</td><td><code>' .
                esc_html( $design['file'] ? $design['file'] : 'Borrador visual' ) . '</code></td><td>' .
                esc_html( implode( ' · ', $design['countries'] ) ) . '</td><td>' .
                ( $design['active'] ? 'Activa en el nuevo enrutador' : 'Borrador / sin activar' ) .
                '</td><td><a class="button button-secondary" href="' . esc_url( $link ) . '">Editar</a></td></tr>';
        }
        echo '</tbody></table>';
    }
}
