<?php
/**
 * TaxoCommerce Distribution administration.
 */

defined( 'ABSPATH' ) || exit;

add_action( 'admin_menu', 'taxocommerce_distribution_register_menu', 42 );
add_action( 'admin_post_tcdist_sitemap_regenerate', 'taxocommerce_distribution_handle_sitemap_regenerate' );

function taxocommerce_distribution_capability() {
    return (string) apply_filters( 'taxocommerce_distribution_capability', 'manage_woocommerce' );
}

function taxocommerce_distribution_guard() {
    if ( ! current_user_can( taxocommerce_distribution_capability() ) && ! current_user_can( 'manage_options' ) ) {
        wp_die( esc_html__( 'You do not have permission to manage TaxoCommerce Distribution.', 'taxocommerce-distribution' ) );
    }
}

function taxocommerce_distribution_register_menu() {
    $capability = taxocommerce_distribution_capability();

    if ( function_exists( 'taxocommerce_core' ) ) {
        add_submenu_page(
            'taxocommerce-core',
            __( 'Distribution', 'taxocommerce-distribution' ),
            __( 'Distribution', 'taxocommerce-distribution' ),
            $capability,
            'taxocommerce-distribution',
            'taxocommerce_distribution_render_page'
        );
        return;
    }

    add_menu_page(
        __( 'TaxoCommerce Distribution', 'taxocommerce-distribution' ),
        __( 'Distribution', 'taxocommerce-distribution' ),
        $capability,
        'taxocommerce-distribution',
        'taxocommerce_distribution_render_page',
        'dashicons-rss',
        59
    );
}

function taxocommerce_distribution_url( $tab = 'overview', $args = array() ) {
    return add_query_arg(
        array_merge(
            array(
                'page'       => 'taxocommerce-distribution',
                'tcdist_tab' => sanitize_key( $tab ),
            ),
            $args
        ),
        admin_url( 'admin.php' )
    );
}

function taxocommerce_distribution_handle_sitemap_regenerate() {
    taxocommerce_distribution_guard();
    check_admin_referer( 'tcdist_sitemap_regenerate' );

    $result = tcdist_sitemap_create_sitemaps();
    $args = array();
    if ( is_array( $result ) && 'success' === (string) ( $result['type'] ?? '' ) ) {
        $args['tcdist_sitemap_result'] = 'ok';
        $args['tcdist_sitemap_message'] = rawurlencode( wp_strip_all_tags( (string) ( $result['message'] ?? '' ) ) );
    } else {
        $args['tcdist_sitemap_result'] = 'error';
        $message = is_array( $result ) ? (string) ( $result['message'] ?? '' ) : __( 'Resultado desconocido.', 'taxocommerce-distribution' );
        $args['tcdist_sitemap_message'] = rawurlencode( wp_strip_all_tags( $message ) );
    }

    wp_safe_redirect( taxocommerce_distribution_url( 'sitemaps', $args ) );
    exit;
}

function taxocommerce_distribution_render_page() {
    taxocommerce_distribution_guard();
    $tab = isset( $_GET['tcdist_tab'] ) ? sanitize_key( wp_unslash( $_GET['tcdist_tab'] ) ) : 'overview'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
    if ( ! in_array( $tab, array( 'overview', 'feeds', 'sitemaps', 'automation' ), true ) ) {
        $tab = 'overview';
    }
    ?>
    <div class="wrap">
        <h1><?php esc_html_e( 'TaxoCommerce Distribution', 'taxocommerce-distribution' ); ?></h1>
        <p><?php esc_html_e( 'Technical catalog publishing: commercial feeds and XML sitemaps through safe bounded processes.', 'taxocommerce-distribution' ); ?></p>

        <nav class="nav-tab-wrapper" aria-label="<?php esc_attr_e( 'Secciones de Distribution', 'taxocommerce-distribution' ); ?>">
            <?php
            foreach (
                array(
                    'overview'   => __( 'Overview', 'taxocommerce-distribution' ),
                    'feeds'      => __( 'Commercial Feeds', 'taxocommerce-distribution' ),
                    'sitemaps'   => __( 'Sitemaps', 'taxocommerce-distribution' ),
                    'automation' => __( 'Automation', 'taxocommerce-distribution' ),
                ) as $key => $label
            ) :
                ?>
                <a class="nav-tab <?php echo esc_attr( $tab === $key ? 'nav-tab-active' : '' ); ?>" href="<?php echo esc_url( taxocommerce_distribution_url( $key ) ); ?>"><?php echo esc_html( $label ); ?></a>
            <?php endforeach; ?>
        </nav>

        <?php taxocommerce_distribution_render_compatibility_notice(); ?>

        <div style="margin-top:18px;">
            <?php
            if ( 'feeds' === $tab ) {
                tcdist_feed_render_admin();
            } elseif ( 'sitemaps' === $tab ) {
                taxocommerce_distribution_render_sitemaps();
            } elseif ( 'automation' === $tab ) {
                taxocommerce_distribution_render_automation();
            } else {
                taxocommerce_distribution_render_overview();
            }
            ?>
        </div>
    </div>
    <?php
}

function taxocommerce_distribution_render_compatibility_notice() {
    $legacy_feed = defined( 'TCDIST_LEGACY_FEED_ACTIVE' ) && TCDIST_LEGACY_FEED_ACTIVE;
    $legacy_sitemap = defined( 'TCDIST_LEGACY_SITEMAP_ACTIVE' ) && TCDIST_LEGACY_SITEMAP_ACTIVE;

    if ( $legacy_feed || $legacy_sitemap ) {
        echo '<div class="notice notice-info inline" style="margin-top:18px"><p><strong>Safe migration:</strong> the legacy plugin was detected. Distribution uses its own state and worker while preserving the same public files. While both plugins coexist, the legacy scheduled automation keeps priority to avoid duplicate execution; manual regeneration can be tested from this plugin.</p></div>';
    }
}

function taxocommerce_distribution_render_overview() {
    $feed_state = function_exists( 'tcdist_feed_state' ) ? tcdist_feed_state() : array();
    $sitemap = function_exists( 'tcdist_sitemap_get_sitemap_report' ) ? tcdist_sitemap_get_sitemap_report() : array();
    $feed_settings = function_exists( 'tcdist_feed_settings' ) ? tcdist_feed_settings() : array();
    $channels = function_exists( 'tcdist_feed_channels' ) ? tcdist_feed_channels() : array();
    $enabled = (array) ( $feed_settings['enabled_channels'] ?? array() );
    ?>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(230px,1fr));gap:16px;max-width:1200px;">
        <div class="card" style="padding:18px;max-width:none;">
            <h2 style="margin-top:0;"><?php esc_html_e( 'Commercial Feeds', 'taxocommerce-distribution' ); ?></h2>
            <p><strong><?php esc_html_e( 'Status:', 'taxocommerce-distribution' ); ?></strong> <?php echo esc_html( (string) ( $feed_state['status'] ?? 'never' ) ); ?></p>
            <p><strong><?php esc_html_e( 'Processed:', 'taxocommerce-distribution' ); ?></strong> <?php echo esc_html( number_format_i18n( absint( $feed_state['processed'] ?? 0 ) ) ); ?></p>
            <p><strong><?php esc_html_e( 'Published:', 'taxocommerce-distribution' ); ?></strong> <?php echo esc_html( number_format_i18n( absint( $feed_state['written'] ?? 0 ) ) ); ?></p>
            <p><a class="button" href="<?php echo esc_url( taxocommerce_distribution_url( 'feeds' ) ); ?>"><?php esc_html_e( 'Open feeds', 'taxocommerce-distribution' ); ?></a></p>
        </div>

        <div class="card" style="padding:18px;max-width:none;">
            <h2 style="margin-top:0;"><?php esc_html_e( 'Active channels', 'taxocommerce-distribution' ); ?></h2>
            <p style="font-size:28px;margin:8px 0;"><strong><?php echo esc_html( number_format_i18n( count( $enabled ) ) ); ?></strong></p>
            <p><?php
            $labels = array();
            foreach ( $enabled as $key ) {
                if ( isset( $channels[ $key ]['label'] ) ) {
                    $labels[] = $channels[ $key ]['label'];
                }
            }
            echo esc_html( implode( ' · ', $labels ) );
            ?></p>
        </div>

        <div class="card" style="padding:18px;max-width:none;">
            <h2 style="margin-top:0;"><?php esc_html_e( 'Sitemaps', 'taxocommerce-distribution' ); ?></h2>
            <p><strong><?php esc_html_e( 'Index:', 'taxocommerce-distribution' ); ?></strong> <?php echo ! empty( $sitemap['main_valid'] ) ? '<span style="color:#1d6b43">Valid</span>' : '<span style="color:#b32d2e">Review</span>'; ?></p>
            <p><strong><?php esc_html_e( 'Published URLs:', 'taxocommerce-distribution' ); ?></strong> <?php echo esc_html( number_format_i18n( absint( $sitemap['total_urls'] ?? 0 ) ) ); ?></p>
            <p><strong><?php esc_html_e( 'Files:', 'taxocommerce-distribution' ); ?></strong> <?php echo esc_html( number_format_i18n( count( (array) ( $sitemap['files'] ?? array() ) ) ) ); ?></p>
            <p><a class="button" href="<?php echo esc_url( taxocommerce_distribution_url( 'sitemaps' ) ); ?>"><?php esc_html_e( 'Open sitemaps', 'taxocommerce-distribution' ); ?></a></p>
        </div>
    </div>

    <div class="card" style="padding:20px;max-width:1200px;margin-top:18px;">
        <h2 style="margin-top:0;"><?php esc_html_e( 'Plugin responsibility', 'taxocommerce-distribution' ); ?></h2>
        <p><?php esc_html_e( 'Distribution publishes the catalog to external systems. It does not edit templates, campaigns, social media, or editorial content.', 'taxocommerce-distribution' ); ?></p>
        <p><strong><?php esc_html_e( 'Feeds:', 'taxocommerce-distribution' ); ?></strong> Google Merchant Center, Microsoft/Bing Shopping, Pinterest, and universal formats.</p>
        <p><strong><?php esc_html_e( 'Sitemaps:', 'taxocommerce-distribution' ); ?></strong> products, categories, posts, pages, clusters, and hubs with a stable XML index.</p>
    </div>
    <?php
}

function taxocommerce_distribution_sitemap_audit_stats() {
    if ( function_exists( 'seo_marketing_scan_stats_by_sitemap' ) ) {
        return (array) seo_marketing_scan_stats_by_sitemap();
    }

    global $wpdb;
    $table = $wpdb->prefix . 'seo_sitemap_inventory';
    $exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
    if ( $exists !== $table ) {
        return array();
    }

    $rows = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT sitemap_url,
                COUNT(*) AS total,
                SUM(last_checked_at IS NOT NULL) AS checked,
                SUM(status_bucket='ok') AS ok_count,
                SUM(status_bucket IN ('3xx','seo_warning')) AS warnings,
                SUM(status_bucket IN ('404','403','4xx','5xx','network','seo_error')) AS errors
             FROM %i
             WHERE active_in_sitemap=1
             GROUP BY sitemap_url",
            $table
        ),
        ARRAY_A
    );

    $out = array();
    foreach ( (array) $rows as $row ) {
        $url = (string) ( $row['sitemap_url'] ?? '' );
        if ( '' === $url ) {
            continue;
        }
        $out[ $url ] = array(
            'total'    => absint( $row['total'] ?? 0 ),
            'checked'  => absint( $row['checked'] ?? 0 ),
            'ok'       => absint( $row['ok_count'] ?? 0 ),
            'warnings' => absint( $row['warnings'] ?? 0 ),
            'errors'   => absint( $row['errors'] ?? 0 ),
        );
    }
    return $out;
}

function taxocommerce_distribution_render_sitemaps() {
    $report = tcdist_sitemap_get_sitemap_report();
    $main_url = tcdist_sitemap_sitemap_public_url( 'sitemap.xml' );
    $audit = taxocommerce_distribution_sitemap_audit_stats();

    $result = isset( $_GET['tcdist_sitemap_result'] ) ? sanitize_key( wp_unslash( $_GET['tcdist_sitemap_result'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
    $message = isset( $_GET['tcdist_sitemap_message'] ) ? sanitize_text_field( rawurldecode( wp_unslash( $_GET['tcdist_sitemap_message'] ) ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
    ?>
    <div class="card" style="max-width:1220px;padding:20px;">
        <h2 style="margin-top:0;"><?php esc_html_e( 'XML Sitemaps', 'taxocommerce-distribution' ); ?></h2>
        <p><?php esc_html_e( 'The same proven engine is preserved: atomic publishing, pre-publication XML validation, product batching, and stable root-domain URLs.', 'taxocommerce-distribution' ); ?></p>
        <p><?php esc_html_e( 'Professional index:', 'taxocommerce-distribution' ); ?> <a href="<?php echo esc_url( $main_url ); ?>" target="_blank" rel="noopener noreferrer"><code><?php echo esc_html( $main_url ); ?></code></a></p>

        <?php if ( $message ) : ?>
            <div class="notice <?php echo esc_attr( 'ok' === $result ? 'notice-success' : 'notice-error' ); ?> inline"><p><?php echo esc_html( $message ); ?></p></div>
        <?php endif; ?>

        <?php if ( ! empty( $report['main_valid'] ) ) : ?>
            <div class="notice notice-success inline"><p><strong><?php esc_html_e( 'Main index is valid.', 'taxocommerce-distribution' ); ?></strong> <?php echo esc_html( sprintf( __( '%s URLs published.', 'taxocommerce-distribution' ), number_format_i18n( absint( $report['total_urls'] ?? 0 ) ) ) ); ?></p></div>
        <?php else : ?>
            <div class="notice notice-warning inline"><p><strong><?php esc_html_e( 'The main index does not exist or is invalid.', 'taxocommerce-distribution' ); ?></strong></p></div>
        <?php endif; ?>

        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin:18px 0;">
            <input type="hidden" name="action" value="tcdist_sitemap_regenerate">
            <?php wp_nonce_field( 'tcdist_sitemap_regenerate' ); ?>
            <?php submit_button( __( 'Regenerate and validate sitemaps', 'taxocommerce-distribution' ), 'primary', 'submit', false ); ?>
        </form>

        <details style="margin:16px 0;">
            <summary><strong><?php esc_html_e( 'What this generation does', 'taxocommerce-distribution' ); ?></strong></summary>
            <p><?php esc_html_e( 'Separates products, categories, posts, pages, clusters, and hubs. Only published, non-password-protected content is included; cart, checkout, and account pages are excluded. Each XML file is validated before replacing the previous public file.', 'taxocommerce-distribution' ); ?></p>
            <p><?php esc_html_e( 'Products are split into bounded groups to avoid oversized XML files. Generation uses a lock and atomic publishing so partial files are never exposed.', 'taxocommerce-distribution' ); ?></p>
        </details>

        <table class="widefat striped">
            <thead><tr><th><?php esc_html_e( 'File', 'taxocommerce-distribution' ); ?></th><th>XML</th><th>URLs</th><th><?php esc_html_e( 'Audited', 'taxocommerce-distribution' ); ?></th><th>OK</th><th><?php esc_html_e( 'Warnings', 'taxocommerce-distribution' ); ?></th><th><?php esc_html_e( 'Errors', 'taxocommerce-distribution' ); ?></th><th><?php esc_html_e( 'Coverage', 'taxocommerce-distribution' ); ?></th><th><?php esc_html_e( 'Size', 'taxocommerce-distribution' ); ?></th><th><?php esc_html_e( 'Modified', 'taxocommerce-distribution' ); ?></th></tr></thead>
            <tbody>
            <?php foreach ( (array) ( $report['files'] ?? array() ) as $file ) : ?>
                <?php
                $is_index = 'sitemap.xml' === (string) ( $file['filename'] ?? '' );
                $stats = ! $is_index && isset( $audit[ $file['url'] ] ) ? $audit[ $file['url'] ] : array( 'checked' => 0, 'ok' => 0, 'warnings' => 0, 'errors' => 0 );
                $count = absint( $file['count'] ?? 0 );
                $coverage = ! $is_index && $count > 0 ? min( 100, round( 100 * absint( $stats['checked'] ?? 0 ) / $count, 1 ) ) : 0;
                ?>
                <tr>
                    <td><a href="<?php echo esc_url( (string) $file['url'] ); ?>" target="_blank" rel="noopener"><code><?php echo esc_html( (string) $file['filename'] ); ?></code></a></td>
                    <td><?php echo ! empty( $file['valid'] ) ? '<span style="color:#1d6b43;font-weight:700">Valid</span>' : '<span style="color:#b32d2e;font-weight:700">Invalid</span>'; ?></td>
                    <td><?php echo esc_html( number_format_i18n( $count ) ); ?></td>
                    <?php if ( $is_index ) : ?>
                        <td>—</td><td>—</td><td>—</td><td>—</td><td>—</td>
                    <?php else : ?>
                        <td><?php echo esc_html( number_format_i18n( absint( $stats['checked'] ?? 0 ) ) ); ?></td>
                        <td><?php echo esc_html( number_format_i18n( absint( $stats['ok'] ?? 0 ) ) ); ?></td>
                        <td><?php echo esc_html( number_format_i18n( absint( $stats['warnings'] ?? 0 ) ) ); ?></td>
                        <td><?php echo esc_html( number_format_i18n( absint( $stats['errors'] ?? 0 ) ) ); ?></td>
                        <td><?php echo esc_html( number_format_i18n( $coverage, 1 ) ); ?>%</td>
                    <?php endif; ?>
                    <td><?php echo esc_html( size_format( absint( $file['bytes'] ?? 0 ), 1 ) ); ?></td>
                    <td><?php echo ! empty( $file['modified'] ) ? esc_html( wp_date( 'd/m/Y H:i', absint( $file['modified'] ) ) ) : '—'; ?></td>
                </tr>
                <?php if ( empty( $file['valid'] ) && ! empty( $file['error'] ) ) : ?>
                    <tr><td colspan="10" style="color:#b32d2e;"><?php echo esc_html( (string) $file['error'] ); ?></td></tr>
                <?php endif; ?>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php
}

function taxocommerce_distribution_render_automation() {
    $feed = tcdist_feed_state();
    $settings = tcdist_feed_settings();
    $legacy_feed = defined( 'TCDIST_LEGACY_FEED_ACTIVE' ) && TCDIST_LEGACY_FEED_ACTIVE;
    $legacy_sitemap = defined( 'TCDIST_LEGACY_SITEMAP_ACTIVE' ) && TCDIST_LEGACY_SITEMAP_ACTIVE;
    ?>
    <div class="card" style="max-width:1000px;padding:20px;">
        <h2 style="margin-top:0;"><?php esc_html_e( 'Automation and workload', 'taxocommerce-distribution' ); ?></h2>
        <p><?php esc_html_e( 'Feeds keep the adaptive regulator from the previous system, but work now runs through the dedicated Distribution worker. Each window uses a cursor, lock, and execution deadline; there is no dependency on the legacy central manager.', 'taxocommerce-distribution' ); ?></p>

        <table class="widefat striped">
            <tbody>
                <tr><th><?php esc_html_e( 'Feed worker', 'taxocommerce-distribution' ); ?></th><td><?php echo $legacy_feed ? esc_html__( 'Legacy automation has priority during coexistence; manual execution is available here.', 'taxocommerce-distribution' ) : esc_html__( 'Owned by Distribution', 'taxocommerce-distribution' ); ?></td></tr>
                <tr><th><?php esc_html_e( 'Daily regeneration', 'taxocommerce-distribution' ); ?></th><td><?php echo ! empty( $settings['auto_refresh'] ) ? esc_html( (string) ( $settings['daily_time'] ?? '03:30' ) ) : esc_html__( 'Disabled', 'taxocommerce-distribution' ); ?></td></tr>
                <tr><th><?php esc_html_e( 'Last batch', 'taxocommerce-distribution' ); ?></th><td><?php echo esc_html( number_format_i18n( absint( $feed['last_batch_rows'] ?? 0 ) ) ); ?> <?php esc_html_e( 'products', 'taxocommerce-distribution' ); ?> · <?php echo esc_html( number_format_i18n( (float) ( $feed['last_batch_duration'] ?? 0 ), 2 ) ); ?> s</td></tr>
                <tr><th><?php esc_html_e( 'Automatic sitemaps', 'taxocommerce-distribution' ); ?></th><td><?php echo $legacy_sitemap ? esc_html__( 'Temporarily managed by the legacy plugin', 'taxocommerce-distribution' ) : esc_html__( 'Distribution regenerates after product, page, post, and category changes', 'taxocommerce-distribution' ); ?></td></tr>
            </tbody>
        </table>

        <p class="description"><?php esc_html_e( 'During migration, two parallel cron jobs are prevented from writing to the same public files. Once the legacy module is removed, Distribution automatically takes over scheduling.', 'taxocommerce-distribution' ); ?></p>
    </div>
    <?php
}
