<?php

defined( 'ABSPATH' ) || exit;

function tcsocial_admin_menu() {
    if ( function_exists( 'taxocommerce_core' ) || defined( 'TAXOCOMMERCE_CORE_VERSION' ) ) {
        add_submenu_page(
            'taxocommerce-core',
            'TaxoCommerce Social',
            'Social',
            'manage_options',
            'taxocommerce-social',
            'tcsocial_admin_render'
        );
        return;
    }

    add_menu_page(
        'TaxoCommerce Social',
        'TaxoCommerce Social',
        'manage_options',
        'taxocommerce-social',
        'tcsocial_admin_render',
        'dashicons-share',
        57
    );
}
add_action( 'admin_menu', 'tcsocial_admin_menu' );

function tcsocial_admin_current_tab() {
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Navigation only.
    $tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';
    // Compatibility with redirects from the migrated legacy handlers.
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Navigation only.
    $legacy = isset( $_GET['social_subtab'] ) ? sanitize_key( wp_unslash( $_GET['social_subtab'] ) ) : '';

    if ( in_array( $legacy, array( 'connections', 'templates' ), true ) ) {
        return $legacy;
    }
    if ( in_array( $legacy, array( 'calendar', 'programador', 'scheduler' ), true ) ) {
        return 'calendar';
    }
    return in_array( $tab, array( 'connections', 'templates', 'calendar' ), true ) ? $tab : 'connections';
}

function tcsocial_admin_tab_url( $tab, $args = array() ) {
    return add_query_arg(
        array_merge(
            array(
                'page' => 'taxocommerce-social',
                'tab'  => sanitize_key( $tab ),
            ),
            is_array( $args ) ? $args : array()
        ),
        admin_url( 'admin.php' )
    );
}

function tcsocial_admin_render() {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }

    tcsocial_network_maybe_install_tables();
    tcsocial_programador_maybe_install_table();

    $tab = tcsocial_admin_current_tab();
    tcsocial_network_render_styles();
    tcsocial_network_render_notice();

    echo '<div class="wrap"><h1>TaxoCommerce Social</h1>';
    echo '<p class="description">Publicación social autónoma. Conexiones, formatos y calendario sin campañas, ofertas ni reglas especiales de Noticias.</p>';

    $settings = tcsocial_network_get_settings();
    echo '<div class="seo-social-header"><div><h2>Redes sociales</h2><p>El calendario es la única agenda de este módulo. Las publicaciones se programan aquí y utilizan las plantillas de cada red.</p></div><div class="seo-social-provider-strip">';
    foreach ( tcsocial_network_get_providers() as $provider_key => $provider ) {
        $connected = ! empty( $settings['providers'][ $provider_key ]['enabled'] );
        echo '<span class="seo-social-provider-pill ' . ( $connected ? 'is-connected' : '' ) . '">';
        echo esc_html( isset( $provider['label'] ) ? $provider['label'] : ucfirst( $provider_key ) );
        echo ' · ' . esc_html( $connected ? 'conectado' : 'sin conectar' );
        echo '</span>';
    }
    echo '</div></div>';

    $tabs = array(
        'connections' => 'Conexiones',
        'templates'   => 'Plantillas',
        'calendar'    => 'Calendario',
    );
    echo '<nav class="nav-tab-wrapper" style="margin-bottom:18px">';
    foreach ( $tabs as $key => $label ) {
        echo '<a class="nav-tab ' . ( $tab === $key ? 'nav-tab-active' : '' ) . '" href="' . esc_url( tcsocial_admin_tab_url( $key ) ) . '">' . esc_html( $label ) . '</a>';
    }
    echo '</nav>';

    if ( 'connections' === $tab ) {
        tcsocial_network_render_connections();
    } elseif ( 'templates' === $tab ) {
        tcsocial_network_render_templates();
    } else {
        echo '<section class="seo-social-card"><div class="seo-social-intro"><div><h2>Agenda social</h2><p>Programa publicaciones manualmente o por CSV y revisa el resultado en el calendario mensual. No se incorporan campañas de Marketing ni se reserva ninguna franja para Noticias.</p></div><span class="seo-social-state is-scheduled">Solo redes sociales</span></div></section>';
        tcsocial_programador_render_admin();
        tcsocial_calendar_render_admin();
    }

    echo '</div>';
}
