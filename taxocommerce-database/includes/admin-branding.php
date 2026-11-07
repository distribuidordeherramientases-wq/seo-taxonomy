<?php
/**
 * Self-contained TaxoCommerce family branding, without a Core dependency.
 * Presentation only: no changes to plugin routing, forms or permissions.
 */
defined( 'ABSPATH' ) || exit;

( static function () {

$tc_brand_slug = basename( dirname( __DIR__ ) );
$tc_brand_main = dirname( __DIR__ ) . '/' . $tc_brand_slug . '.php';
$tc_brand_on_screen = static function () use ( $tc_brand_slug ) {
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only WordPress admin route.
    $page = isset( $_GET['page'] ) && is_string( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
    return $page === $tc_brand_slug || 0 === strpos( $page, $tc_brand_slug . '-' );
};

add_filter( 'admin_body_class', static function ( $classes ) use ( $tc_brand_on_screen ) {
    return $tc_brand_on_screen() ? $classes . ' tc-family-screen' : $classes;
} );

add_action( 'admin_enqueue_scripts', static function () use ( $tc_brand_on_screen, $tc_brand_slug ) {
    if ( ! $tc_brand_on_screen() ) {
        return;
    }
    wp_register_style( $tc_brand_slug . '-family-header', false, array(), '1.0.0' );
    wp_enqueue_style( $tc_brand_slug . '-family-header' );
    wp_add_inline_style( $tc_brand_slug . '-family-header', ".tc-family-hero{position:relative;display:flex;align-items:center;gap:22px;min-height:155px;margin:18px 20px 17px 0;padding:28px 32px;overflow:hidden;box-sizing:border-box;color:#17243b;border:1px solid #dfe6ef;border-left:4px solid #1769de;border-radius:13px;background:linear-gradient(110deg,#fff 0%,#f7faff 70%,#e9f1ff 100%);box-shadow:0 3px 14px rgba(20,45,85,.055)}.tc-family-hero *{box-sizing:border-box}.tc-family-hero__emblem{z-index:1;display:grid;place-items:center;width:74px;height:74px;flex:0 0 74px;border-radius:50%;background:#e7f0ff;color:#1769de}.tc-family-hero__emblem .dashicons{width:43px;height:43px;font-size:43px}.tc-family-hero__copy{z-index:1;max-width:760px}.tc-family-hero__eyebrow{margin:0 0 5px!important;font-size:11px;font-weight:800;letter-spacing:.09em;text-transform:uppercase;color:#7686a0}.tc-family-hero h1{margin:0 0 8px;font-size:30px;line-height:1.2;letter-spacing:-.035em;font-weight:750;color:#122038}.tc-family-hero__desc{margin:0!important;max-width:750px;font-size:14px;line-height:1.6;color:#47576f}.tc-family-hero__version{position:absolute;z-index:2;right:19px;top:17px;padding:5px 11px;border:1px solid #bdd4f5;border-radius:20px;background:#f1f6ff;color:#145fc5;font-size:12px;font-weight:700}.tc-family-hero__art{position:absolute;right:45px;top:25px;width:150px;height:115px;transform:rotate(-30deg) skew(15deg);opacity:.30;pointer-events:none}.tc-family-hero__art i{position:absolute;display:block;width:68px;height:68px;border:2px solid #bbd5fc;background:#dae8ff;box-shadow:9px 11px 0 #e2ecff}.tc-family-hero__art i:nth-child(1){left:75px;top:0;background:#347ef0}.tc-family-hero__art i:nth-child(2){left:0;top:24px}.tc-family-hero__art i:nth-child(3){left:112px;top:77px}body.tc-family-screen #wpcontent{background:#f4f6f9}body.tc-family-screen .wrap .nav-tab-wrapper{display:flex;flex-wrap:wrap;gap:7px;border-bottom:1px solid #dfe6ef;padding:0 0 9px}body.tc-family-screen .wrap .nav-tab{margin:0;background:#fff;border:1px solid #dfe6ef;border-radius:7px;padding:9px 14px;font-weight:650;color:#455572}body.tc-family-screen .wrap .nav-tab-active{background:#e9f2ff;border-color:#b5d0f5;color:#115bc7}@media(max-width:880px){.tc-family-hero__art{opacity:.10}}@media(max-width:600px){.tc-family-hero{min-height:125px;padding:22px 14px;gap:14px;margin-right:10px}.tc-family-hero__emblem{width:48px;height:48px;flex-basis:48px}.tc-family-hero__emblem .dashicons{font-size:28px;width:28px;height:28px}.tc-family-hero h1{font-size:23px}.tc-family-hero__version{right:8px;top:8px}.tc-family-hero__art{display:none}}.tc-family-hero__meta{position:relative;z-index:2;display:flex;flex-direction:column;align-items:flex-end;gap:7px;margin-left:auto;flex:0 0 auto}.tc-family-hero__meta .tc-family-hero__version{position:static}.tc-family-hero__updated{padding:5px 11px;border:1px solid #dfe6ef;border-radius:20px;background:#fff;color:#435369;font-size:12px;font-weight:600;white-space:nowrap}@media(max-width:980px){.tc-family-hero{flex-wrap:wrap}.tc-family-hero__meta{margin-left:96px;align-items:flex-start}}@media(max-width:600px){.tc-family-hero__meta{margin-left:62px}}" );
} );

add_action( 'admin_notices', static function () use ( $tc_brand_on_screen, $tc_brand_main ) {
    if ( ! $tc_brand_on_screen() || ! is_readable( $tc_brand_main ) ) {
        return;
    }
    $plugin = get_file_data( $tc_brand_main, array( 'name' => 'Plugin Name', 'version' => 'Version', 'released' => 'Release Date' ) );
    $title = preg_replace( '/\\s+-\\s+.*$/', '', (string) ( $plugin['name'] ?? '' ) );
    $version = (string) ( $plugin['version'] ?? '' );
    $released = (string) ( $plugin['released'] ?? '' );
    // Prefer native plugin gettext catalogs, with predictable fallbacks for
    // the five supported base languages until new catalogs are compiled.
    $tc_version_label = __( 'Version', 'taxocommerce-database' );
    $tc_updated_label = __( 'Last updated', 'taxocommerce-database' );
    $tc_languages = array(
        'es' => array( 'Versión', 'Última actualización' ),
        'en' => array( 'Version', 'Last updated' ),
        'fr' => array( 'Version', 'Dernière mise à jour' ),
        'de' => array( 'Version', 'Zuletzt aktualisiert' ),
        'nl' => array( 'Versie', 'Laatst bijgewerkt' ),
    );
    $tc_language = strtolower( substr( determine_locale(), 0, 2 ) );
    if ( isset( $tc_languages[ $tc_language ] ) ) {
        if ( 'Version' === $tc_version_label ) {
            $tc_version_label = $tc_languages[ $tc_language ][0];
        }
        if ( 'Last updated' === $tc_updated_label ) {
            $tc_updated_label = $tc_languages[ $tc_language ][1];
        }
    }

    if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $released ) ) {
        $released = '';
    }
    if ( '' === $title ) {
        return;
    }
    ?>
    <header class="tc-family-hero">
        <span class="tc-family-hero__emblem" aria-hidden="true"><span class="dashicons dashicons-database"></span></span>
        <div class="tc-family-hero__copy">
            <p class="tc-family-hero__eyebrow">TaxoCommerce</p>
            <h1><?php echo esc_html( $title ); ?></h1>
            <p class="tc-family-hero__desc"><?php esc_html_e( 'Consulta y mantén las tablas de datos de WordPress y WooCommerce.', 'taxocommerce-database' ); ?></p>
        </div>
        <div class="tc-family-hero__meta">
            <span class="tc-family-hero__version"><?php echo esc_html( $tc_version_label . ' ' . $version ); ?></span>
            <span class="tc-family-hero__updated"><?php echo esc_html( $tc_updated_label . ': ' . $released ); ?></span>
        </div>
        <span class="tc-family-hero__art" aria-hidden="true"><i></i><i></i><i></i></span>
    </header>
    <?php
}, 5 );
} )();
