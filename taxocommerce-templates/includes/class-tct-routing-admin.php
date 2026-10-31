<?php
/**
 * Single routing registry: native WordPress/WooCommerce requests and SEO
 * page roles both use the existing seo_templates mappings.
 *
 * This is a configuration screen, not a replacement for template hierarchy.
 *
 * @package TaxoCommerceTemplates
 */
defined( 'ABSPATH' ) || exit;

final class TCT_Routing_Admin {
    public static function enqueue() {
        if ( ! current_user_can( 'manage_options' ) || ! isset( $_GET['page'] )
            || ! is_string( $_GET['page'] ) || 'taxocommerce-templates' !== $_GET['page'] ) {
            return;
        }
        $tab = isset( $_GET['tab'] ) && is_string( $_GET['tab'] )
            ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'routing';
        if ( ! in_array( $tab, array( 'routing', 'roles', 'requests', 'audit' ), true ) ) {
            return;
        }
        wp_enqueue_style( 'tct-routing-admin', TCT_URL . 'assets/routing-admin.css', array(), TCT_VERSION );
    }

    public static function items() {
        $defs = TCT_Request_Registry::definitions();
        $items = array();
        foreach ( $defs as $key => $def ) {
            if ( ! is_array( $def ) || count( $def ) < 3 ) {
                continue;
            }
            $key = sanitize_key( (string) $key );
            if ( '' === $key ) {
                continue;
            }
            $items[ $key ] = array(
                'label' => (string) $def[0],
                'group' => (string) $def[1],
                'native' => (string) $def[2],
                'role' => '',
            );
        }
        foreach ( TCT_Router::catalog() as $key => $data ) {
            if ( 'automatic' === $data[3] && isset( $items[ $key ] ) ) {
                continue;
            }
            if ( ! isset( $items[ $key ] ) ) {
                $items[ $key ] = array(
                    'label' => (string) $data[0],
                    'group' => 'TaxoCommerce - roles y rutas',
                    'native' => '',
                    'role' => '',
                );
            }
            if ( 'automatic' !== $data[3] ) {
                $items[ $key ]['role'] = $key;
                $items[ $key ]['group'] = 'TaxoCommerce - roles y rutas';
            }
        }
        if ( TaxoCommerce_Templates::installed() ) {
            foreach ( TCT_Router::rows() as $row ) {
                $key = (string) $row->template_key;
                if ( ! isset( $items[ $key ] ) ) {
                    $items[ $key ] = array(
                        'label' => (string) $row->template_name,
                        'group' => 'TaxoCommerce - roles y rutas',
                        'native' => '',
                        'role' => '',
                    );
                }
                if ( (int) $row->is_assignable && in_array( $row->assignment_mode, array( 'single', 'multiple' ), true ) ) {
                    $items[ $key ]['role'] = $key;
                    $items[ $key ]['group'] = 'TaxoCommerce - roles y rutas';
                }
            }
        }
        return $items;
    }

    public static function save() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Acceso denegado.', 'taxocommerce-templates' ) );
        }
        check_admin_referer( 'tct_save_route_mapping' );
        if ( ! TaxoCommerce_Templates::installed() ) {
            self::redirect( 'No están disponibles las tablas del gestor.' );
        }
        $key = isset( $_POST['route_key'] ) && is_string( $_POST['route_key'] )
            ? sanitize_key( wp_unslash( $_POST['route_key'] ) ) : '';
        $file = isset( $_POST['template_file'] ) && is_string( $_POST['template_file'] )
            ? sanitize_file_name( wp_unslash( $_POST['template_file'] ) ) : '';
        $items = self::items();
        if ( '' === $key || ! isset( $items[ $key ] ) ) {
            self::redirect( 'La ruta seleccionada no está registrada.' );
        }
        if ( '' !== $file && ( ! TCT_Router::valid_filename( $file ) || ! TCT_Router::path( $file ) ) ) {
            self::redirect( 'La plantilla no existe en la biblioteca autorizada.' );
        }

        global $wpdb;
        $table = TaxoCommerce_Templates::templates();
        $old = $wpdb->get_row( $wpdb->prepare(
            "SELECT id FROM {$table} WHERE template_key = %s LIMIT 1", $key
        ) );
        $now = current_time( 'mysql' );
        if ( $old ) {
            // Keep role assignments, type, per-device variants and metadata intact.
            $saved = $wpdb->update(
                $table,
                array( 'template_file' => $file, 'is_active' => '' !== $file ? 1 : 0, 'updated_at' => $now ),
                array( 'id' => (int) $old->id )
            );
        } else {
            // Only known request definitions may create automatic routes.
            $defs = TCT_Request_Registry::definitions();
            if ( ! isset( $defs[ $key ] ) ) {
                self::redirect( 'Antes de asociar esta ruta debes registrar el rol en TaxoCommerce.' );
            }
            $group = (string) $defs[ $key ][1];
            $type = 0 === strpos( $group, 'WooCommerce - correos' ) ? 'email'
                : ( 0 === strpos( $group, 'WooCommerce' ) ? 'woocommerce' : 'system' );
            $saved = $wpdb->insert( $table, array(
                'template_key' => $key,
                'template_name' => (string) $defs[ $key ][0],
                'template_file' => $file,
                'template_type' => $type,
                'assignment_mode' => 'automatic',
                'is_assignable' => 0,
                'is_public' => 1,
                'is_active' => '' !== $file ? 1 : 0,
                'created_at' => $now,
                'updated_at' => $now,
            ) );
        }
        self::redirect( false === $saved
            ? 'No se pudo guardar el enrutamiento.'
            : ( '' === $file ? 'Ruta devuelta a WordPress / tema.' : 'Relación petición → plantilla guardada.' ) );
    }

    private static function redirect( $message ) {
        $group = isset( $_POST['tct_group'] ) && is_string( $_POST['tct_group'] )
            ? sanitize_text_field( wp_unslash( $_POST['tct_group'] ) ) : '';
        $search = isset( $_POST['tct_search'] ) && is_string( $_POST['tct_search'] )
            ? sanitize_text_field( wp_unslash( $_POST['tct_search'] ) ) : '';
        wp_safe_redirect( TCT_Request_Admin::admin_url( 'routing', array(
            'tct_group' => $group,
            'tct_search' => substr( $search, 0, 120 ),
            'tct_notice' => $message,
        ) ) );
        exit;
    }

    public static function render() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Acceso denegado.', 'taxocommerce-templates' ) );
        }
        $installed = TaxoCommerce_Templates::installed();
        $enabled = (bool) get_option( TaxoCommerce_Templates::ENABLE_OPTION, 0 );
        $items = self::items();
        $mapping = array();
        if ( $installed ) {
            foreach ( TCT_Router::rows() as $row ) {
                $mapping[ $row->template_key ] = $row;
            }
        }
        $files = TCT_Router::available_files();
        $groups = array( 'all' => 'Todas las peticiones' );
        foreach ( $items as $item ) {
            $groups[ $item['group'] ] = $item['group'];
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- UI filters are read-only.
        $group = isset( $_GET['tct_group'] ) && is_string( $_GET['tct_group'] )
            ? sanitize_text_field( wp_unslash( $_GET['tct_group'] ) ) : 'all';
        if ( ! isset( $groups[ $group ] ) ) {
            $group = 'all';
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- UI filters are read-only.
        $search = isset( $_GET['tct_search'] ) && is_string( $_GET['tct_search'] )
            ? substr( sanitize_text_field( wp_unslash( $_GET['tct_search'] ) ), 0, 120 ) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- UI pagination is read-only.
        $page = isset( $_GET['tct_paged'] ) ? max( 1, absint( $_GET['tct_paged'] ) ) : 1;
        $visible = array();
        foreach ( $items as $key => $item ) {
            if ( 'all' !== $group && $item['group'] !== $group ) {
                continue;
            }
            if ( '' !== $search && false === stripos(
                $key . ' ' . $item['label'] . ' ' . $item['group'] . ' ' . $item['native'] .
                ' ' . ( isset( $mapping[ $key ] ) ? $mapping[ $key ]->template_file : '' ), $search
            ) ) {
                continue;
            }
            $visible[ $key ] = $item;
        }
        $total = count( $visible );
        $page_size = 40;
        $visible = array_slice( $visible, ( $page - 1 ) * $page_size, $page_size, true );

        echo '<div class="wrap tct-routing-admin">';
        echo '<h2>Enrutamiento de plantillas</h2>';
        echo '<p>Selecciona qué archivo usa cada petición de WordPress, WooCommerce o rol SEO. Las asociaciones se guardan en <code>seo_templates</code>, sin modificar archivos PHP.</p>';
        echo '<div class="notice notice-' . ( $enabled ? 'success' : 'warning' ) . ' inline"><p><strong>';
        echo $enabled ? 'Enrutador TaxoCommerce habilitado.' : 'Enrutador TaxoCommerce desactivado.';
        echo '</strong> ';
        echo $enabled
            ? 'Las rutas especializadas y el tema pueden tener prioridad; esta tabla muestra configuraciones, no una ejecución comprobada.'
            : 'Puedes guardar relaciones, pero no sustituirán la plantilla pública mientras el enrutador esté desactivado.';
        echo '</p></div>';
        if ( $installed ) {
            echo '<details class="tct-routing-activation"><summary>Activación del enrutador (avanzado)</summary>';
            echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
            wp_nonce_field( 'tct_settings' );
            echo '<input type="hidden" name="action" value="tct_settings">';
            echo '<label><input type="checkbox" name="enable" value="1" ' . checked( $enabled, true, false ) . '> Activar el enrutador TaxoCommerce Templates</label>';
            echo '<p class="description">Activar solo tras comprobar rutas y dependencias en STAGING. Esta opción ya existía; no se cambia automáticamente.</p>';
            submit_button( 'Guardar activación', 'secondary', 'submit', false );
            echo '</form></details>';
        } else {
            echo '<div class="notice notice-error inline"><p>Las tablas de plantillas no están disponibles; no se pueden guardar asociaciones.</p></div>';
        }

        echo '<form method="get" action="' . esc_url( admin_url( 'admin.php' ) ) . '" class="tct-routing-filters">';
        echo '<input type="hidden" name="page" value="taxocommerce-templates"><input type="hidden" name="tab" value="routing">';
        echo '<label for="tct-routing-group">Familia</label><select id="tct-routing-group" name="tct_group">';
        foreach ( $groups as $code => $label ) {
            echo '<option value="' . esc_attr( $code ) . '" ' . selected( $group, $code, false ) . '>' . esc_html( $label ) . '</option>';
        }
        echo '</select><label for="tct-routing-search">Buscar</label>';
        echo '<input id="tct-routing-search" name="tct_search" type="search" value="' . esc_attr( $search ) . '" placeholder="Rol, petición o archivo">';
        submit_button( 'Filtrar', 'secondary', 'submit', false );
        echo '</form>';
        echo '<p><strong>' . (int) $total . '</strong> relaciones encontradas. <a href="' .
            esc_url( TCT_Request_Admin::admin_url( 'designer' ) ) . '">Ir al Diseñador de plantillas</a></p>';

        echo '<div class="tct-routing-scroll"><table class="widefat striped tct-routing-table"><thead><tr>';
        echo '<th>Petición WordPress / WooCommerce</th><th>Rol SEO</th><th>Archivo / plantilla</th><th>Estado y origen</th><th>Diseñador</th>';
        echo '</tr></thead><tbody>';
        if ( ! $visible ) {
            echo '<tr><td colspan="5">No hay rutas que coincidan con el filtro.</td></tr>';
        }
        foreach ( $visible as $key => $item ) {
            $row = isset( $mapping[ $key ] ) ? $mapping[ $key ] : null;
            $selected = $row ? (string) $row->template_file : '';
            $valid = '' !== $selected && (bool) TCT_Router::path( $selected );
            $active = $row && (int) $row->is_active;
            $request_defs = TCT_Request_Registry::definitions();
            $editor_url = TCT_Request_Admin::admin_url( 'designer', array(
                'tct_source' => $valid ? $selected : '',
                // The code editor auto-associates only registered request
                // keys. SEO page roles are assigned in this routing table.
                'tct_assign' => isset( $request_defs[ $key ] ) ? $key : '',
            ) );
            echo '<tr><td><strong>' . esc_html( $item['label'] ) . '</strong>';
            echo '<br><code>' . esc_html( $key ) . '</code><br><small>' . esc_html( $item['group'] ) . '</small>';
            if ( '' !== $item['native'] ) {
                echo '<br><small>WordPress: <code>' . esc_html( $item['native'] ) . '</code></small>';
            }
            echo '</td><td>' . ( '' !== $item['role'] ? '<code>' . esc_html( $item['role'] ) . '</code>' : 'Automático / sin rol SEO' ) . '</td>';
            echo '<td>';
            echo '<form action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" method="post">';
            wp_nonce_field( 'tct_save_route_mapping' );
            echo '<input type="hidden" name="action" value="tct_save_route_mapping">';
            echo '<input type="hidden" name="route_key" value="' . esc_attr( $key ) . '">';
            echo '<input type="hidden" name="tct_group" value="' . esc_attr( $group ) . '">';
            echo '<input type="hidden" name="tct_search" value="' . esc_attr( $search ) . '">';
            echo '<select name="template_file" aria-label="Plantilla para ' . esc_attr( $item['label'] ) . '" ' . disabled( ! $installed, true, false ) . '>';
            echo '<option value="">WordPress / Tema (sin asignación)</option>';
            if ( '' !== $selected && ! in_array( $selected, $files, true ) ) {
                echo '<option selected disabled value="' . esc_attr( $selected ) . '">' . esc_html( $selected . ' (no disponible)' ) . '</option>';
            }
            foreach ( $files as $file ) {
                echo '<option value="' . esc_attr( $file ) . '" ' . selected( $file, $selected, false ) . '>' . esc_html( $file ) . '</option>';
            }
            echo '</select> ';
            submit_button( 'Guardar', 'secondary', 'submit', false, $installed ? array() : array( 'disabled' => 'disabled' ) );
            echo '</form></td><td>';
            if ( ! $active || '' === $selected ) {
                echo '<strong>WordPress / Tema</strong>';
            } elseif ( ! $valid ) {
                echo '<strong class="tct-routing-error">Archivo no encontrado</strong>';
            } elseif ( ! $enabled ) {
                echo '<strong>Configurado; motor desactivado</strong>';
            } else {
                echo '<strong>Asignación activa</strong>';
            }
            if ( in_array( $key, array( 'taxonomy_product_cat', 'single_product', 'landing' ), true ) ) {
                echo '<br><small>VEVOR US/DE/NL/FR: ruta especializada con prioridad.</small>';
            }
            echo '</td><td><a href="' . esc_url( $editor_url ) . '">Ver / editar plantilla</a></td></tr>';
        }
        echo '</tbody></table></div>';

        $pages = (int) ceil( $total / $page_size );
        if ( $pages > 1 ) {
            echo '<p class="tablenav-pages">';
            for ( $n = 1; $n <= $pages; ++$n ) {
                if ( $n === $page ) {
                    echo ' <strong>' . (int) $n . '</strong> ';
                } else {
                    echo ' <a href="' . esc_url( TCT_Request_Admin::admin_url( 'routing', array(
                        'tct_group' => $group, 'tct_search' => $search, 'tct_paged' => $n,
                    ) ) ) . '">' . (int) $n . '</a> ';
                }
            }
            echo '</p>';
        }

        echo '<details class="tct-routing-special"><summary>Excepciones y diagnósticos del enrutamiento</summary>';
        echo '<p>Las rutas VEVOR internacionales pueden ser atendidas por el piloto especializado y el antiguo monolito antes de la tabla general. También tienen rutas propias Dependiente y los componentes WooCommerce por bloques.</p>';
        echo '<p>La asignación de una ruta no garantiza que sea la plantilla PHP finalmente ejecutada. Para verificar una URL real deben comprobarse el rol, el país y el filtro <code>template_include</code> del servidor.</p>';
        echo '<p><a href="' . esc_url( TCT_Request_Admin::admin_url( 'audit' ) ) . '">Abrir auditoría técnica de asociaciones existentes</a></p>';
        echo '<p><a href="' . esc_url( TCT_Request_Admin::admin_url( 'roles' ) ) . '">Gestionar las páginas asignadas a cada rol</a></p>';
        echo '</details></div>';
    }
}
