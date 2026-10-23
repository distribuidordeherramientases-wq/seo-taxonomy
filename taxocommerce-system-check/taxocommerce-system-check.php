<?php
/**
 * Plugin Name: TaxoCommerce System Check
 * Description: Chequeos independientes del servidor, WordPress y plugins, con informes y correo opcional.
 * Version: 0.2.8
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * Author: David Perez
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: taxocommerce-system-check
 */

defined( 'ABSPATH' ) || exit;

define( 'TAXOCOMMERCE_SYSTEM_CHECK_VERSION', '0.2.8' );
define( 'TAXOCOMMERCE_SYSTEM_CHECK_FILE', __FILE__ );
define( 'TAXOCOMMERCE_SYSTEM_CHECK_PATH', plugin_dir_path( __FILE__ ) );
define( 'TAXOCOMMERCE_SYSTEM_CHECK_URL', plugin_dir_url( __FILE__ ) );

/**
 * Reuse the legacy engine if TaxoCommerce SEO is active. Its functions are
 * global; loading the original modules twice would cause PHP fatal errors.
 */
function taxocommerce_system_check_boot() {
    $legacy_active = defined( 'SEO_SYSTEM_FILE' )
        && wp_normalize_path( (string) SEO_SYSTEM_FILE ) !== wp_normalize_path( TAXOCOMMERCE_SYSTEM_CHECK_FILE );

    // The installed legacy plugin owns SEO_SYSTEM_* constants, even when
    // temporarily inactive (for example during WordPress activation sandbox).
    // Never claim its global namespace from this independent extension.
    $legacy_installed = is_readable( WP_PLUGIN_DIR . '/seo-taxonomy/seo-taxonomy.php' );
    if ( ! $legacy_active && ! $legacy_installed ) {
        if ( ! defined( 'SEO_SYSTEM_FILE' ) ) {
            define( 'SEO_SYSTEM_FILE', TAXOCOMMERCE_SYSTEM_CHECK_FILE );
        }
        if ( ! defined( 'SEO_SYSTEM_PATH' ) ) {
            define( 'SEO_SYSTEM_PATH', TAXOCOMMERCE_SYSTEM_CHECK_PATH );
        }
        if ( ! defined( 'SEO_SYSTEM_URL' ) ) {
            define( 'SEO_SYSTEM_URL', TAXOCOMMERCE_SYSTEM_CHECK_URL );
        }
        if ( ! defined( 'SEO_SYSTEM_VERSION' ) ) {
            define( 'SEO_SYSTEM_VERSION', TAXOCOMMERCE_SYSTEM_CHECK_VERSION );
        }
        if ( ! defined( 'SEO_SYSTEM_BASENAME' ) ) {
            define( 'SEO_SYSTEM_BASENAME', plugin_basename( TAXOCOMMERCE_SYSTEM_CHECK_FILE ) );
        }
        // Never send a customer's site diagnostics to a developer by default.
        if ( ! defined( 'SEO_SYSTEM_DIAGNOSTICS_RECIPIENT' ) ) {
            define( 'SEO_SYSTEM_DIAGNOSTICS_RECIPIENT', sanitize_email( (string) get_option( 'admin_email', '' ) ) );
        }

        foreach ( array(
            'seo-system-server-status.php',
            'seo-core-validation.php',
            'seo-system-diagnostics-reporting.php',
        ) as $module ) {
            $path = TAXOCOMMERCE_SYSTEM_CHECK_PATH . 'includes/system-check/' . $module;
            if ( is_readable( $path ) ) {
                require_once $path;
            }
        }
    }

    // Load the existing health inventory schema only when the SEO monolith
    // has not already registered its global functions and REST routes.
    if ( ! function_exists( 'seo_health_scan_tables' ) ) {
        $inventory_module = TAXOCOMMERCE_SYSTEM_CHECK_PATH . 'includes/system-check/seo-health-scan.php';
        if ( is_readable( $inventory_module ) ) {
            require_once $inventory_module;
        }
    }
    require_once TAXOCOMMERCE_SYSTEM_CHECK_PATH . 'includes/class-taxocommerce-system-check-php.php';
    $url_audit_module = TAXOCOMMERCE_SYSTEM_CHECK_PATH . 'includes/class-taxocommerce-system-check-url-audit.php';
    if ( is_readable( $url_audit_module ) ) {
        require_once $url_audit_module;
        TaxoCommerce_System_Check_URL_Audit::register_hooks();
    }

    add_action( 'taxocommerce_core_register_modules', 'taxocommerce_system_check_register_with_core' );
    // Core registers at plugins_loaded:20; this plugin boots at priority 100.
    // Register immediately if Core has already completed its bootstrap.
    if ( function_exists( 'taxocommerce_core' ) && did_action( 'taxocommerce_core_loaded' ) ) {
        taxocommerce_system_check_register_with_core( taxocommerce_core() );
    }
    add_action( 'admin_post_taxocommerce_system_check_modules_json', 'taxocommerce_system_check_modules_json' );
    add_action( 'admin_menu', 'taxocommerce_system_check_register_menu', 60 );
    add_action( 'admin_enqueue_scripts', 'taxocommerce_system_check_enqueue_assets' );
    add_action( 'admin_enqueue_scripts', 'taxocommerce_system_check_family_styles' );
    add_filter( 'admin_body_class', 'taxocommerce_system_check_body_class' );
    if ( ! $legacy_active ) {
        add_action( 'admin_menu', 'taxocommerce_system_check_register_legacy_routes', 100 );
    }
}
add_action( 'plugins_loaded', 'taxocommerce_system_check_boot', 100 );

function taxocommerce_system_check_register_with_core( $core ) {
    if ( is_object( $core ) && method_exists( $core, 'register_module' ) ) {
        $core->register_module(
            'system_check',
            array(
                'name'        => 'TaxoCommerce System Check',
                'version'     => TAXOCOMMERCE_SYSTEM_CHECK_VERSION,
                'status'      => 'active',
                'description' => 'Diagnostico de sistema, validacion del plugin e informes.',
                'admin_url'   => admin_url( 'admin.php?page=taxocommerce-system-check' ),
            )
        );
    }
}

function taxocommerce_system_check_register_menu() {
    $in_core = function_exists( 'taxocommerce_core' ) || defined( 'TAXOCOMMERCE_CORE_VERSION' );
    if ( ! $in_core ) {
        add_menu_page( 'TaxoCommerce System Check', 'System Check', 'manage_options', 'taxocommerce-system-check', 'taxocommerce_system_check_home', 'dashicons-shield-alt', 62 );
    } else {
        add_submenu_page( 'taxocommerce-core', 'System Check', 'System Check', 'manage_options', 'taxocommerce-system-check', 'taxocommerce_system_check_home' );
    }
    add_submenu_page( null, 'PHP independiente', 'PHP independiente', 'manage_options', 'tcs-native-php', array( 'TaxoCommerce_System_Check_PHP', 'render' ) );
    // Previously published routes remain available without visible menu entries.
    add_submenu_page( null, 'Estado del sistema', 'Estado del sistema', 'manage_options', 'tcs-system-status', 'taxocommerce_system_check_server_screen' );
    add_submenu_page( null, 'Plugin Validation', 'Plugin Validation', 'manage_options', 'tcs-plugin-validation', 'taxocommerce_system_check_validation_screen' );
    add_submenu_page( null, 'Chequeos HTTP', 'Chequeos HTTP', 'manage_options', 'tcs-url-audit', 'taxocommerce_system_check_url_audit_screen' );
}

/** The original plugin also uses these historic admin route names. */
function taxocommerce_system_check_register_legacy_routes() {
    add_submenu_page( null, 'Plugin Validation', 'Plugin Validation', 'manage_options', 'seo-core-system-test', 'taxocommerce_system_check_validation_screen' );
    add_submenu_page( null, 'Estado del sistema', 'Estado del sistema', 'manage_options', 'seo_server_status', 'taxocommerce_system_check_server_screen' );
}

/** Exactly one parent screen owns the three feature tabs. */
function taxocommerce_system_check_tab_url( $tab, $args = array() ) {
    return add_query_arg(
        array_merge( array( 'page' => 'taxocommerce-system-check', 'tcs_tab' => $tab ), $args ),
        admin_url( 'admin.php' )
    );
}

function taxocommerce_system_check_active_tab() {
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only tab selection.
    $tab = isset( $_GET['tcs_tab'] ) ? sanitize_key( wp_unslash( $_GET['tcs_tab'] ) ) : 'system';
    return in_array( $tab, array( 'system', 'plugin', 'http' ), true ) ? $tab : 'system';
}

function taxocommerce_system_check_render_tabs( $active ) {
    echo '<nav class="nav-tab-wrapper" aria-label="Chequeos de System Check">';
    foreach ( array( 'system' => 'Sistema', 'plugin' => 'Plugin Validation', 'http' => 'HTTP' ) as $tab => $label ) {
        echo '<a class="nav-tab' . ( $active === $tab ? ' nav-tab-active' : '' ) . '" href="'
            . esc_url( taxocommerce_system_check_tab_url( $tab ) ) . '">'
            . esc_html( $label ) . '</a>';
    }
    echo '</nav>';
}

/**
 * Load the shared family design only on System Check's own pages.
 * Never enqueue it inside TaxoCommerce SEO / legacy diagnostics.
 */
function taxocommerce_system_check_family_styles( $hook ) {
    if ( defined( 'TAXOCOMMERCE_CORE_VERSION' ) ) {
        return;
    }
    $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
    $screen_id = $screen ? (string) $screen->id : '';
    $is_own_page = false !== strpos( (string) $hook, 'taxocommerce-system-check' )
        || false !== strpos( $screen_id, 'taxocommerce-system-check' )
        || false !== strpos( (string) $hook, 'tcs-' )
        || false !== strpos( $screen_id, 'tcs-' );
    if ( ! $is_own_page ) {
        return;
    }
    wp_enqueue_style(
        'taxocommerce-family-admin',
        TAXOCOMMERCE_SYSTEM_CHECK_URL . 'assets/admin.css',
        array(),
        TAXOCOMMERCE_SYSTEM_CHECK_VERSION
    );
}

/**
 * Add family scope only to standalone System Check screens.
 */
function taxocommerce_system_check_body_class( $classes ) {
    if ( defined( 'TAXOCOMMERCE_CORE_VERSION' ) ) {
        return $classes;
    }
    $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
    $id = $screen ? (string) $screen->id : '';
    return ( false !== strpos( $id, 'taxocommerce-system-check' ) || false !== strpos( $id, 'tcs-' ) )
        ? $classes . ' tcc-family-admin'
        : $classes;
}

function taxocommerce_system_check_enqueue_assets() {
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin page routing.
    $page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
    if ( in_array( $page, array( 'tcs-plugin-validation', 'seo-core-system-test' ), true ) || ( 'taxocommerce-system-check' === $page && 'plugin' === taxocommerce_system_check_active_tab() ) ) {
        wp_enqueue_style(
            'taxocommerce-system-check-validation',
            TAXOCOMMERCE_SYSTEM_CHECK_URL . 'includes/system-check/assets/seo-core-validation.css',
            array(),
            TAXOCOMMERCE_SYSTEM_CHECK_VERSION
        );
    }
}

add_action( 'admin_head', 'taxocommerce_system_check_audit_styles' );
function taxocommerce_system_check_audit_styles() {
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin page selector.
    $page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
    if ( 'tcs-url-audit' !== $page && !( 'taxocommerce-system-check' === $page && 'http' === taxocommerce_system_check_active_tab() ) ) {
        return;
    }
    echo '<style>
    .tcs-url-audit .tcs-stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(135px,1fr));gap:10px;margin:20px 0;}
    .tcs-url-audit .tcs-stat{background:#fff;border:1px solid #dcdcde;border-radius:6px;padding:16px;}
    .tcs-url-audit .tcs-stat strong{display:block;font-size:25px;line-height:1.2}
    .tcs-url-audit .tcs-stat span{color:#50575e;font-size:12px}
    .tcs-url-audit .tcs-actions,.tcs-url-audit .tcs-filter{display:flex;flex-wrap:wrap;align-items:center;gap:8px;margin:15px 0;}
    .tcs-url-audit .tcs-actions form{margin:0}
    .tcs-url-audit .tcs-last{background:#fff;border:1px solid #dcdcde;padding:12px;margin:12px 0}
    .tcs-url-audit table td{max-width:420px;overflow-wrap:anywhere}
    </style>';
}

function taxocommerce_system_check_home() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( esc_html__( 'Acceso no autorizado.', 'taxocommerce-system-check' ) );
    }
    $tab = taxocommerce_system_check_active_tab();
    echo '<div class="wrap tcc-family-screen">';
    echo '<header class="tcc-page-header"><span class="tcc-page-header__icon" aria-hidden="true"><span class="dashicons dashicons-shield-alt"></span></span>';
    echo '<div class="tcc-page-header__content"><p class="tcc-page-header__eyebrow">' . esc_html__( 'Familia TaxoCommerce', 'taxocommerce-system-check' ) . '</p>';
    echo '<h1>' . esc_html__( 'TaxoCommerce System Check', 'taxocommerce-system-check' ) . '</h1>';
    echo '<p>' . esc_html__( 'Diagnósticos del servidor, comprobación de plugins y auditorías HTTP desde una única interfaz.', 'taxocommerce-system-check' ) . '</p></div></header>';
    taxocommerce_system_check_render_tabs( $tab );
    // Each tab downloads its stored results. No scan is started here.
    $exports = array(
        'system' => array( 'seo_system_diagnostics_download_server_json', 'Descargar JSON de Sistema' ),
        'plugin' => array( 'taxocommerce_system_check_modules_json', 'Descargar JSON de comprobación básica' ),
        'http'   => array( 'taxocommerce_system_check_http_json', 'Descargar JSON de HTTP' ),
    );
    if ( isset( $exports[ $tab ] ) ) {
        $action = $exports[ $tab ][0];
        $args = array( 'action' => $action );
        if ( 'http' === $tab ) {
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only scope used in export link.
            $requested = isset( $_GET['tcs_scope'] ) ? sanitize_key( wp_unslash( $_GET['tcs_scope'] ) ) : 'page';
            $args['tcs_scope'] = in_array( $requested, array( 'page', 'post', 'image', 'product' ), true ) ? $requested : 'page';
        }
        $url = wp_nonce_url( add_query_arg( $args, admin_url( 'admin-post.php' ) ), $action );
        echo '<p><a class="button button-secondary" href="' . esc_url( $url ) . '">'
            . esc_html( $exports[ $tab ][1] ) . '</a></p>';
        if ( 'plugin' === $tab ) {
            $history_url = wp_nonce_url( add_query_arg( array( 'action' => 'seo_system_diagnostics_download_validation_json' ), admin_url( 'admin-post.php' ) ), 'seo_system_diagnostics_download_validation_json' );
            echo '<p><a class="button button-secondary" href="' . esc_url( $history_url ) . '">Descargar JSON de chequeos anteriores (histórico)</a></p>';
        }
        if ( 'system' === $tab ) {
            $complete_url = wp_nonce_url( add_query_arg( array( 'action' => 'seo_system_diagnostics_download_json' ), admin_url( 'admin-post.php' ) ), 'seo_system_diagnostics_download_json' );
            echo '<p><a class="button button-secondary" href="' . esc_url( $complete_url ) . '">Descargar JSON completo</a></p>';
        }
    }
    echo '</div>';
    if ( 'plugin' === $tab ) {
        taxocommerce_system_check_validation_screen();
    } elseif ( 'http' === $tab ) {
        taxocommerce_system_check_url_audit_screen();
    } else {
        taxocommerce_system_check_server_screen();
    }
}

function taxocommerce_system_check_server_screen() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( esc_html__( 'Acceso no autorizado.', 'taxocommerce-system-check' ) );
    }
    echo '<div class="notice notice-info inline"><p><a href="' . esc_url( admin_url( 'admin.php?page=tcs-native-php' ) ) . '">Abrir diagnóstico PHP independiente</a> · Nueva implementación autónoma.</p></div>';
    if ( function_exists( 'seo_server_status' ) ) {
        seo_server_status();
        return;
    }
    echo '<div class="wrap"><h1>Estado del sistema</h1><div class="notice notice-error"><p>Motor original no disponible.</p></div></div>';
}

function taxocommerce_system_check_registered_modules() {
    $modules = array(
        'system_check' => array(
            'name' => 'TaxoCommerce System Check',
            'version' => TAXOCOMMERCE_SYSTEM_CHECK_VERSION,
            'status' => 'active',
        ),
    );
    if ( function_exists( 'taxocommerce_core' ) ) {
        $core = taxocommerce_core();
        if ( is_object( $core ) && method_exists( $core, 'get_registered_modules' ) ) {
            foreach ( (array) $core->get_registered_modules() as $id => $module ) {
                if ( is_array( $module ) && 'active' === ( $module['status'] ?? '' ) ) {
                    $modules[ sanitize_key( $id ) ] = $module;
                }
            }
        }
    }
    return $modules;
}

/** Basic checks available for every active module without static module lists. */
function taxocommerce_system_check_module_checks( $id, $module ) {
    $checks = array(
        'registered' => array( 'ok' => true, 'label' => 'Cargado y registrado' ),
        'version'    => array(
            'ok'    => ! empty( $module['version'] ),
            'label' => 'Versión declarada',
        ),
    );
    if ( 'system_check' === $id ) {
        $checks['server'] = array(
            'ok'    => function_exists( 'seo_server_status' ),
            'label' => 'Motor de diagnóstico del sistema disponible',
        );
        $checks['http'] = array(
            'ok'    => class_exists( 'TaxoCommerce_System_Check_URL_Audit' ),
            'label' => 'Auditor HTTP disponible',
        );
    }
    return $checks;
}

function taxocommerce_system_check_module_results() {
    $results = array();
    foreach ( taxocommerce_system_check_registered_modules() as $id => $module ) {
        $checks = taxocommerce_system_check_module_checks( $id, $module );
        $failures = count( array_filter( $checks, static function ( $check ) { return ! $check['ok']; } ) );
        $results[ $id ] = array(
            'name'    => $module['name'] ?? $id,
            'version' => $module['version'] ?? '',
            'status'  => $failures ? 'warning' : 'ok',
            'checks'  => $checks,
        );
    }
    return $results;
}

function taxocommerce_system_check_modules_json() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( esc_html__( 'Acceso no autorizado.', 'taxocommerce-system-check' ) );
    }
    check_admin_referer( 'taxocommerce_system_check_modules_json' );
    nocache_headers();
    header( 'Content-Type: application/json; charset=UTF-8' );
    header( 'X-Content-Type-Options: nosniff' );
    header( 'Content-Disposition: attachment; filename="taxocommerce-active-modules.json"' );
    echo wp_json_encode( array(
        'generated_at' => gmdate( 'c' ),
        'source' => function_exists( 'taxocommerce_core' ) ? 'core_runtime_registry' : 'standalone',
        'modules' => taxocommerce_system_check_module_results(),
        'note' => 'Comprobación básica de carga; no equivale a un chequeo funcional exhaustivo.',
    ), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON download.
    exit;
}

function taxocommerce_system_check_validation_screen() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( esc_html__( 'Acceso no autorizado.', 'taxocommerce-system-check' ) );
    }
    $modules = taxocommerce_system_check_module_results();

    echo '<div class="wrap"><h2>Plugin Validation · módulos activos</h2>';
    echo '<p>Sin Core solo se comprueba System Check. Con Core se usan sus módulos activos; no hay una lista duplicada.</p>';
    echo '<table class="widefat striped"><thead><tr><th>Plugin</th><th>Versión</th><th>Comprobación básica</th></tr></thead><tbody>';
    foreach ( $modules as $module ) {
        $messages = array();
        foreach ( $module['checks'] as $check ) {
            $messages[] = ( $check['ok'] ? 'OK: ' : 'Aviso: ' ) . $check['label'];
        }
        echo '<tr><th scope="row">' . esc_html( (string) $module['name'] ) . '</th>';
        echo '<td>' . esc_html( (string) $module['version'] ) . '</td>';
        echo '<td>' . esc_html( implode( ' · ', $messages ) ) . '</td></tr>';
    }
    echo '</tbody></table>';
    echo '<p class="description">Comprobación de carga y servicios básicos de System Check. No sustituye todavía los chequeos profundos, que se adaptarán por módulo.</p>';
    echo '</div>';
}

function taxocommerce_system_check_url_audit_screen() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( esc_html__( 'No autorizado.', 'taxocommerce-system-check' ) );
    }
    if ( class_exists( 'TaxoCommerce_System_Check_URL_Audit' ) ) {
        TaxoCommerce_System_Check_URL_Audit::render();
        return;
    }
    echo '<div class="wrap"><h1>Chequeos HTTP</h1><div class="notice notice-error"><p>El módulo de auditoría HTTP no se ha cargado.</p></div></div>';
}

function taxocommerce_system_check_deactivate() {
    if ( class_exists( 'TaxoCommerce_System_Check_URL_Audit' ) ) {
        TaxoCommerce_System_Check_URL_Audit::deactivate();
    }
    if ( defined( 'SEO_SYSTEM_FILE' ) && wp_normalize_path( (string) SEO_SYSTEM_FILE ) !== wp_normalize_path( TAXOCOMMERCE_SYSTEM_CHECK_FILE ) ) {
        return;
    }
    wp_clear_scheduled_hook( 'seo_system_diagnostics_scheduled_report' );
    wp_clear_scheduled_hook( 'seo_system_diagnostics_after_update_report' );
}
register_deactivation_hook( __FILE__, 'taxocommerce_system_check_deactivate' );
