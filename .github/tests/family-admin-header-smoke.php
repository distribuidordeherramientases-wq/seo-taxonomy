<?php
/**
 * Standalone admin branding regression tests: no WordPress runtime required.
 * Example: php .github/tests/family-admin-header-smoke.php
 */
error_reporting( E_ALL );
define( 'ABSPATH', dirname( __DIR__, 2 ) . '/' );
$tc_test_actions = array();
$tc_test_filters = array();
$tc_test_styles = array();

function add_action( $hook, $callback, $priority = 10 ) {
    global $tc_test_actions;
    $tc_test_actions[ $hook ][] = $callback;
    return true;
}
function add_filter( $hook, $callback, $priority = 10 ) {
    global $tc_test_filters;
    $tc_test_filters[ $hook ][] = $callback;
    return true;
}
function sanitize_key( $value ) {
    return preg_replace( '/[^a-z0-9_-]/', '', strtolower( (string) $value ) );
}
function wp_unslash( $value ) { return $value; }

function esc_attr( $value ) { return esc_html( $value ); }
function esc_url( $value ) { return esc_html( $value ); }
function esc_url_raw( $value, $protocols = array() ) { return (string) $value; }
function admin_url( $path ) { return 'https://example.test/wp-admin/' . $path; }
function current_user_can( $capability ) { return 'manage_options' === $capability; }
function wp_nonce_field( $name ) { echo '<input type="hidden" name="_wpnonce" value="test">'; }
function get_option( $key, $default = '' ) { return $GLOBALS['tc_test_options'][ $key ] ?? $default; }
function update_option( $key, $value, $autoload = null ) {
    $GLOBALS['tc_test_options'][ $key ] = $value;
    return true;
}
function get_transient( $key ) { return $GLOBALS['tc_test_transients'][ $key ] ?? false; }
function set_transient( $key, $value, $ttl ) { $GLOBALS['tc_test_transients'][ $key ] = $value; return true; }
function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }
function sanitize_title( $text ) { return strtolower( preg_replace( '/[^a-z0-9-]/', '-', $text ) ); }
function add_query_arg( $args, $url ) { return $url . '?' . http_build_query( $args ); }
function wp_safe_remote_get( $url, $args = array() ) {
    $GLOBALS['tc_test_remote_calls'][] = $url;
    if ( 'http_error' === ( $GLOBALS['tc_test_remote_mode'] ?? '' ) ) {
        return array( 'code' => 503, 'body' => 'Unavailable' );
    }
    if ( 'invalid_json' === ( $GLOBALS['tc_test_remote_mode'] ?? '' ) ) {
        return array( 'code' => 200, 'body' => 'not valid JSON' );
    }
    $url_parts = parse_url( $url );
    parse_str( $url_parts['query'] ?? '', $query_parts );
    $slug = $query_parts['slug'] ?? '';
    $source_link = $url_parts['scheme'] . '://' . $url_parts['host'] . '/' . $slug . '/';
    return array( 'code' => 200, 'body' => wp_json_encode( array( array(
        'link' => $source_link,
        'excerpt' => array( 'rendered' => '<p>Dynamic &amp; safe excerpt</p>' ),
    ) ) ) );
}
function is_wp_error( $value ) { return false; }
function wp_remote_retrieve_response_code( $response ) { return $response['code']; }
function wp_remote_retrieve_body( $response ) { return $response['body']; }
function wp_json_encode( $value ) { return json_encode( $value ); }
function wp_strip_all_tags( $text ) { return strip_tags( $text ); }
function wp_html_excerpt( $text, $max, $more = '' ) { return strlen( $text ) > $max ? substr( $text, 0, $max ) . $more : $text; }
function absint( $value ) { return abs( (int) $value ); }
define( 'HOUR_IN_SECONDS', 3600 );
define( 'MINUTE_IN_SECONDS', 60 );
$GLOBALS['tc_test_remote_calls'] = array();
$GLOBALS['tc_test_transients'] = array();

function __( $text, $domain = '' ) { return $text; }
function translate( $text, $domain = 'default' ) { return $text; }
function determine_locale() { return $GLOBALS['tc_test_locale'] ?? 'es_ES'; }
function esc_html( $value ) {
    return htmlspecialchars( (string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
}
function esc_html_e( $value, $domain = '' ) {
    echo esc_html( $value );
}
function wp_register_style( $handle, $src, $deps, $version ) {
    global $tc_test_styles;
    $tc_test_styles[ $handle ] = array( 'src' => $src, 'version' => $version, 'css' => '' );
}
function wp_enqueue_style( $handle ) {
    global $tc_test_styles;
    if ( ! isset( $tc_test_styles[ $handle ] ) ) {
        throw new RuntimeException( 'Nonregistered stylesheet: ' . $handle );
    }
}
function wp_add_inline_style( $handle, $css ) {
    global $tc_test_styles;
    $tc_test_styles[ $handle ]['css'] = $css;
}
function get_file_data( $file, $fields ) {
    $source = file_get_contents( $file );
    $result = array();
    foreach ( $fields as $field => $name ) {
        $value = '';
        if ( preg_match( '/^\s*\*\s*' . preg_quote( $name, '/' ) . ':\s*(.+)$/mi', $source, $matches ) ) {
            $value = trim( $matches[1] );
        }
        $result[ $field ] = $value;
    }
    return $result;
}

function tc_test_assert( $passed, $message ) {
    if ( ! $passed ) {
        fwrite( STDERR, "FAIL: " . $message . PHP_EOL );
        exit( 1 );
    }
}

$slugs = array(
    'auditor', 'redirects', 'faqs', 'images', 'social', 'database',
    'campaigns', 'suppliers', 'scrappers', 'logistica', 'vocabulary',
    'facturacion', 'dependiente', 'google-bing', 'distribution',
    'import-export', 'system-check', 'templates', 'tasks',
);
$root = dirname( __DIR__, 2 );
foreach ( $slugs as $slug ) {
    $path = $root . '/taxocommerce-' . $slug . '/includes/admin-branding.php';
    tc_test_assert( is_file( $path ), 'Missing self-contained header: ' . $slug );
    require $path;
}
tc_test_assert( count( $tc_test_actions['admin_notices'] ?? array() ) === count( $slugs ), 'One header registration per standalone plugin.' );
tc_test_assert( count( $tc_test_actions['admin_enqueue_scripts'] ?? array() ) === count( $slugs ), 'One style registration per standalone plugin.' );
foreach ( $slugs as $slug ) {
    $_GET['page'] = 'taxocommerce-' . $slug;
    ob_start();
    foreach ( $tc_test_actions['admin_notices'] as $callback ) {
        $callback();
    }
    $html = ob_get_clean();
    tc_test_assert( 1 === substr_count( $html, '<header class="tc-family-hero">' ), 'Exactly one hero on ' . $slug );
    tc_test_assert( 1 === substr_count( $html, 'tc-family-hero__version' ), 'Exactly one version on ' . $slug );
    tc_test_assert( 1 === substr_count( $html, 'tc-family-hero__updated' ), 'Exactly one release date on ' . $slug );
    tc_test_assert( strpos( $html, 'tc-family-hero__copy' ) !== false, 'Title and description on ' . $slug );
    tc_test_assert( strpos( $html, 'dashicons-' ) !== false, 'Native WordPress icon on ' . $slug );
    tc_test_assert( 1 === substr_count( $html, '<details class="tc-family-history">' ), 'Exactly one collapsed version history: ' . $slug );
    tc_test_assert( false !== strpos( $html, '<summary>Historial de versiones y mejoras</summary>' ), 'Translated history title: ' . $slug );
    tc_test_assert( false === strpos( $html, '<details class="tc-family-history" open' ), 'History must be closed by default: ' . $slug );
    tc_test_assert( 1 === substr_count( $html, '<details class="tc-family-info">' ), 'One official excerpt panel: ' . $slug );
    tc_test_assert( 1 === substr_count( $html, '<div class="tc-family-panels">' ), 'One centered header controls zone: ' . $slug );
    tc_test_assert( false === strpos( $html, 'name="tc_official_url"' ), 'The developer-owned link cannot be changed by a shop administrator: ' . $slug );
    tc_test_assert( false !== strpos( $html, 'Dynamic &amp; safe excerpt' ), 'Default official Plugin URI must load dynamic excerpt: ' . $slug );
    tc_test_assert( strpos( $html, '<details class="tc-family-info">' ) < strpos( $html, '<details class="tc-family-history">' ), 'Official excerpt at left of local history: ' . $slug );
    tc_test_assert( false !== strpos( $html, '<summary>Información del plugin</summary>' ), 'Official excerpt translated in Spanish: ' . $slug );
    tc_test_assert( false === strpos( $html, 'tc-family-info" open' ), 'Official excerpt closed by default: ' . $slug );
    tc_test_assert( is_readable( $root . '/taxocommerce-' . $slug . '/includes/official-excerpt.php' ), 'Independent REST excerpt source: ' . $slug );
    $changelog = $root . '/taxocommerce-' . $slug . '/CHANGELOG.md';
    tc_test_assert( is_readable( $changelog ), 'Bundled history is required: ' . $slug );
    $first_line = strtok( file_get_contents( $changelog ), "\n" );
    tc_test_assert( false !== strpos( $html, esc_html( $first_line ) ), 'Header reads the current plugin history: ' . $slug );
    tc_test_assert( false === strpos( $html, '__TC_DOMAIN__' ), 'No unresolved gettext placeholder: ' . $slug );
    $version = get_file_data( $root . '/taxocommerce-' . $slug . '/taxocommerce-' . $slug . '.php', array( 'version' => 'Version', 'released' => 'Release Date' ) );
    tc_test_assert( 1 === preg_match( '/^\d{4}-\d{2}-\d{2}$/', $version['released'] ), 'Valid release date on ' . $slug );
    tc_test_assert( false !== strpos( $html, $version['released'] ), 'Visible release date matches plugin header on ' . $slug );
    tc_test_assert( false !== strpos( $html, $version['version'] ), 'Visible version follows current plugin header: ' . $slug );
    $class = 'wp-admin';
    foreach ( $tc_test_filters['admin_body_class'] as $callback ) {
        $class = $callback( $class );
    }
    tc_test_assert( 1 === substr_count( $class, 'tc-family-screen' ), 'Only owning plugin marks admin body on ' . $slug );
    $tc_test_styles = array();
    foreach ( $tc_test_actions['admin_enqueue_scripts'] as $callback ) {
        $callback();
    }
    tc_test_assert( 1 === count( $tc_test_styles ), 'No cross-plugin styles enqueued on ' . $slug );
    $styles = reset( $tc_test_styles );
    tc_test_assert( false !== strpos( $styles['css'], '@media(max-width:600px)' ), 'Responsive header on ' . $slug );
    tc_test_assert( false !== strpos( $styles['css'], '.nav-tab-wrapper' ), 'Existing tabs retained on ' . $slug );
    tc_test_assert( false !== strpos( $styles['css'], 'grid-template-columns:74px minmax(' ), 'Three-zone centered header: ' . $slug );
}
// The same standalone header must respect each WordPress administrator locale.
$_GET['page'] = 'taxocommerce-auditor';
foreach ( array(
    'es_ES' => 'Última actualización',
    'en_US' => 'Last updated',
    'fr_FR' => 'Dernière mise à jour',
    'de_DE' => 'Zuletzt aktualisiert',
    'nl_NL' => 'Laatst bijgewerkt',
) as $locale => $expected ) {
    $GLOBALS['tc_test_locale'] = $locale;
    ob_start();
    foreach ( $tc_test_actions['admin_notices'] as $callback ) {
        $callback();
    }
    $header = ob_get_clean();
    tc_test_assert( false !== strpos( $header, $expected ), 'Translated release label for ' . $locale );
}
// The history control responds to all five administrator language variants.
$_GET['page'] = 'taxocommerce-auditor';
foreach ( array(
    'es_ES' => 'Historial de versiones y mejoras',
    'en_US' => 'Version history and improvements',
    'fr_FR' => 'Historique des versions et améliorations',
    'de_DE' => 'Versionsverlauf und Verbesserungen',
    'nl_NL' => 'Versiegeschiedenis en verbeteringen',
) as $locale => $label ) {
    $GLOBALS['tc_test_locale'] = $locale;
    ob_start();
    foreach ( $tc_test_actions['admin_notices'] as $callback ) {
        $callback();
    }
    $markup = ob_get_clean();
    tc_test_assert( false !== strpos( $markup, '<summary>' . esc_html( $label ) . '</summary>' ), 'History respects site locale: ' . $locale );
}
$GLOBALS['tc_test_remote_calls'] = array();
// Developer Plugin URI is authoritative, even if an old shop-specific option exists.
$GLOBALS['tc_test_transients'] = array();
$GLOBALS['tc_test_remote_calls'] = array();
$GLOBALS['tc_test_options']['tc_official_page_taxocommerce-auditor'] = 'https://client.example/override/';
$_GET['page'] = 'taxocommerce-auditor';
$GLOBALS['tc_test_locale'] = 'es_ES';
$official = get_file_data( $root . '/taxocommerce-auditor/taxocommerce-auditor.php', array( 'uri' => 'Plugin URI' ) );
ob_start();
foreach ( $tc_test_actions['admin_notices'] as $callback ) { $callback(); }
$remote_markup = ob_get_clean();
tc_test_assert( 1 === count( $GLOBALS['tc_test_remote_calls'] ), 'The developer page loads through one safe WordPress REST request.' );
tc_test_assert( false !== strpos( $remote_markup, 'Dynamic &amp; safe excerpt' ), 'Excerpt must be escaped and HTML removed.' );
tc_test_assert( false !== strpos( $remote_markup, esc_html( $official['uri'] ) ), 'The developer Plugin URI link is visible.' );
tc_test_assert( false === strpos( $remote_markup, 'https://client.example' ), 'Legacy shop option cannot override the official Plugin URI.' );
tc_test_assert( false === strpos( $remote_markup, 'tc_official_url' ), 'No customer-visible official URL edit field.' );
ob_start();
foreach ( $tc_test_actions['admin_notices'] as $callback ) { $callback(); }
ob_end_clean();
tc_test_assert( 1 === count( $GLOBALS['tc_test_remote_calls'] ), 'Repeated visits read from transient cache.' );

// The previous release cached empty results for 15 minutes. Versioned keys
// bypass those legacy negative caches, without changing the official source.
$legacy_key = 'tc_official_excerpt_' . md5( $official['uri'] );
$cache_key  = 'tc_official_excerpt_v2_' . md5( $official['uri'] );
$last_key   = 'tc_official_excerpt_good_' . md5( $official['uri'] );
tc_test_assert( ! empty( $GLOBALS['tc_test_options'][ $last_key ] ), 'Successful REST excerpt persists as verified last-good text.' );
$GLOBALS['tc_test_transients'][ $legacy_key ] = array( 'text' => '' );
unset( $GLOBALS['tc_test_transients'][ $cache_key ] );
$GLOBALS['tc_test_remote_mode'] = 'http_error';
ob_start();
foreach ( $tc_test_actions['admin_notices'] as $callback ) { $callback(); }
$stale_markup = ob_get_clean();
tc_test_assert( 2 === count( $GLOBALS['tc_test_remote_calls'] ), 'Retry must ignore negative transients from an earlier release.' );
tc_test_assert( false !== strpos( $stale_markup, 'Dynamic &amp; safe excerpt' ), 'Network failure shows last verified developer excerpt.' );
tc_test_assert( false === strpos( $stale_markup, 'temporalmente no disponible' ), 'Do not show empty excerpt while last-good exists.' );

// A fresh installation with an unreachable official API must still show
// the author's own plugin Description, never content from the customer site.
unset( $GLOBALS['tc_test_transients'][ $cache_key ], $GLOBALS['tc_test_options'][ $last_key ] );
ob_start();
foreach ( $tc_test_actions['admin_notices'] as $callback ) { $callback(); }
$offline_markup = ob_get_clean();
$plugin_description = get_file_data( $root . '/taxocommerce-auditor/taxocommerce-auditor.php', array( 'description' => 'Description' ) );
tc_test_assert( false !== strpos( $offline_markup, esc_html( $plugin_description['description'] ) ), 'Offline fallback uses plugin-author Description.' );
tc_test_assert( false === strpos( $offline_markup, 'Extracto temporalmente no disponible.' ), 'Offline fallback hides misleading missing-excerpt warning.' );
tc_test_assert( false === strpos( $offline_markup, 'https://client.example' ), 'Customer site never supplies official excerpt.' );
$before = count( $GLOBALS['tc_test_remote_calls'] );
ob_start();
foreach ( $tc_test_actions['admin_notices'] as $callback ) { $callback(); }
ob_end_clean();
tc_test_assert( $before === count( $GLOBALS['tc_test_remote_calls'] ), 'Briefly cached failures prevent repeated HTTP calls.' );

unset( $GLOBALS['tc_test_transients'][ $cache_key ] );
$GLOBALS['tc_test_remote_mode'] = 'invalid_json';
ob_start();
foreach ( $tc_test_actions['admin_notices'] as $callback ) { $callback(); }
$bad_json_markup = ob_get_clean();
tc_test_assert( false !== strpos( $bad_json_markup, esc_html( $plugin_description['description'] ) ), 'Malformed remote JSON still uses author fallback.' );

unset( $GLOBALS['tc_test_transients'][ $cache_key ] );
$GLOBALS['tc_test_remote_mode'] = '';
ob_start();
foreach ( $tc_test_actions['admin_notices'] as $callback ) { $callback(); }
$recovered_markup = ob_get_clean();
tc_test_assert( false !== strpos( $recovered_markup, 'Dynamic &amp; safe excerpt' ), 'Recovered REST endpoint replaces fallback and last-good.' );
tc_test_assert( false !== strpos( $recovered_markup, esc_html( $official['uri'] ) ), 'Official developer URL preserved after offline recovery.' );
unset( $GLOBALS['tc_test_remote_mode'] );
unset( $GLOBALS['tc_test_locale'] );
unset( $_GET['page'] );
ob_start();
foreach ( $tc_test_actions['admin_notices'] as $callback ) {
    $callback();
}
tc_test_assert( '' === ob_get_clean(), 'No branding outside plugin admin pages.' );
// Core, Tasks and the compatibility bridge have independent admin rendering.
foreach ( array( 'core' ) as $other ) {
    $source = $root . '/taxocommerce-' . $other . '/taxocommerce-' . $other . '.php';
    $data = get_file_data( $source, array( 'version' => 'Version', 'released' => 'Release Date' ) );
    tc_test_assert( 1 === preg_match( '/^\\d+\\.\\d+\\.\\d+$/', $data['version'] ), 'Valid plugin version: ' . $other );
    tc_test_assert( 1 === preg_match( '/^\d{4}-\d{2}-\d{2}$/', $data['released'] ), 'Valid release date present: ' . $other );
    $readme = file_get_contents( $root . '/taxocommerce-' . $other . '/readme.txt' );
    tc_test_assert( false !== strpos( $readme, 'Stable tag: ' . $data['version'] ), 'Stable tag current: ' . $other );
    $main = file_get_contents( $source );
    tc_test_assert( false !== strpos( $main, 'TAXOCOMMERCE_' . strtoupper( $other ) . '_RELEASE_DATE' ), 'Release-date constant: ' . $other );
}
$core_ui = file_get_contents( $root . '/taxocommerce-core/includes/class-taxocommerce-core-dashboard.php' );
$tasks_ui = file_get_contents( $root . '/taxocommerce-tasks/taxocommerce-tasks.php' );
tc_test_assert( false !== strpos( $core_ui, 'tcc-hero__metadata' ) && false !== strpos( $core_ui, 'TAXOCOMMERCE_CORE_RELEASE_DATE' ), 'Core hero version and date' );
tc_test_assert( false !== strpos( $core_ui, 'tcc-hero__panels' ), 'Core presents the developer information and local changelog in a separate centered zone.' );
tc_test_assert( false !== strpos( $core_ui, '<details class="tcc-hero__history">' ), 'Core has a collapsed history on its own dashboard.' );
tc_test_assert( false !== strpos( $core_ui, "dirname( __DIR__ ) . '/CHANGELOG.md'" ), 'Core reads its own local changelog.' );
tc_test_assert( false !== strpos( $core_ui, 'esc_html( $tc_history )' ), 'Core history is HTML-escaped.' );
tc_test_assert( is_readable( $root . '/taxocommerce-core/CHANGELOG.md' ), 'Core changelog must be included in the release.' );
tc_test_assert( false !== strpos( $tasks_ui, "require_once __DIR__ . '/includes/admin-branding.php';" ), 'Tasks loads the shared autonomous visual header' );
tc_test_assert( false === strpos( $tasks_ui, '<div class="wrap"><h1>' ), 'Tasks old duplicate heading removed' );
tc_test_assert( false !== strpos( $tasks_ui, '<div class="wrap"><nav class="nav-tab-wrapper">' ), 'Tasks keeps its seven-tab navigation below the hero' );
$tasks_data = get_file_data( $root . '/taxocommerce-tasks/taxocommerce-tasks.php', array( 'version' => 'Version', 'released' => 'Release Date' ) );
tc_test_assert( false !== strpos( file_get_contents( $root . '/taxocommerce-tasks/readme.txt' ), 'Stable tag: ' . $tasks_data['version'] ), 'Tasks release version matches readme' );
tc_test_assert( false !== strpos( $core_ui, "'/official-excerpt.php'" ), 'Core must load standalone official excerpt.' );
echo "PASS: 20 local version histories + optional safe REST excerpts (cached) + Core." . PHP_EOL;
